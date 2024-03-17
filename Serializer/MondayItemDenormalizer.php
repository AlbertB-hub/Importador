<?php

use Service\MondayService;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class MondayItemDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    //private const ALREADY_CALLED = 'MONDAY_DENORMALIZER_ALREADY_CALLED';

    use DenormalizerAwareTrait;

    function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface  $parameterBag,
        private readonly MondayService          $mondayService
    )
    {

    }

    public function supportsDenormalization(mixed $data, string $type, string $format = null, array $context = [])
    {
        return in_array(MondayItemInterface::class, class_implements($type));
    }

    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): array
    {
        $repository = $this->entityManager->getRepository($type);
        $existingObjects = new ArrayCollection($repository->findAll());
        $processedObjects = new ArrayCollection();

        $assetIds = [];
        $newMondayIds = [];

        if (isset($context['subItemClass'])) {
            $newMondaySubItemIds = [];
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->getPropertyAccessor();

        $afterOperations = [];

        foreach ($data as $item) {
            $object = $existingObjects->findFirst(function (int $key, MondayItemInterface $value) use ($item, $context) {
                return $value->getMondayId() == $item[$context['itemId']];
            });

            if (null === $object) {
                $object = new $type();
            }

            foreach ($item['column_values'] as $column_value) {
                if (!array_key_exists($column_value["id"], $context['columns'])) {
                    continue;
                }

                $config = $context['columns'][$column_value["id"]];
                $value = null;

                // SubItems
                if ($config["type"] === 'subtasks' && !empty($item['subitems'])) {
                    $subTaskConfig = (array)$this->parameterBag->get($config["bag"]);
                    $subTasks = $this->denormalizer->denormalize($item["subitems"], $subTaskConfig['class'], null, $subTaskConfig['context']);

                    $propertyAccessor->setValue($object, $config['name'], $subTasks['objects']);

                    foreach ($subTasks['assetsQueue'] as $key => $value) {
                        $assetIds[strval($key)] = $value['ids'];
                        $assetIds[strval($key)]['itemType'] = $value['itemType'];
                    }

                    foreach ($subTasks['newMondayIds'] as $mondayIds) {
                        $newMondaySubItemIds = array_merge($newMondaySubItemIds, $mondayIds);
                    }

                    $newMondayIds[$subTaskConfig['class']] = $newMondaySubItemIds;
                    continue;
                }

                // WorkDocs
                if ($config["type"] === 'workDoc') {
                    $workDoc = json_decode($column_value["value"], true);
                    if (empty($workDoc)) {
                        continue;
                    }

                    // Comprobación porque existen workDocs vacíos
                    $workDoc = reset($workDoc["files"]);

                    if (!$workDoc) {
                        continue;
                    }

                    $workDocConfig = (array)$this->parameterBag->get('app.monday.board.work_doc_blocks');
                    $workDocId = $workDoc["objectId"];
                    $workDocResponse = $this->mondayService->getWorkdocById($workDocId);

                    $this->denormalizer->denormalize(reset($workDocResponse["docs"])["blocks"], clase_work_doc::class, null, ['columns' => $workDocConfig['context'], 'fileType' => $workDoc["fileType"], 'material' => $object])['objects'];

                    continue;
                }

                // Ids de archivos adjuntos para crear colas
                if ($config["type"] === 'file') {
                    $files = json_decode($column_value['value'], true);

                    if (!$files) {
                        continue;
                    }

                    $assetIds[$item['id']]['ids'][$config['name']] = array_values(array_map(function ($file) {
                        return $file['assetId'];
                    }, $files['files']));

                    $assetIds[$item['id']]['itemType'] = $type;
                    continue;
                }

                if ($config['type'] === 'related_same' || $config['type'] === 'board_relation_item') {
                    $values = json_decode($column_value['value'], true);
                    if (empty($values['linkedPulseIds'])) {
                        continue;
                    }
                    $afterOperations[] = [
                        'config' => $config,
                        'values' => $values,
                        'object' => $object
                    ];

                    continue;
                } else {
                    $value = match ($config['type']) {
                        'integer' => (int)$column_value['text'],
                        'check' => (bool)json_decode($column_value['value'], true)["checked"],
                        'date' => json_decode($column_value['value'], true) ? new DateTime(json_decode($column_value['value'], true)['date']) : null,
                        'board_relation_name' => !empty($column_value['display_value']) ? $column_value['display_value'] : null,
                        'board_relation_array' => !empty($column_value['display_value']) ? explode(',', $column_value['display_value']) : [],
                        'status' => json_decode($column_value['text']),
                        'tag' => !empty($column_value['tag_ids']) ? $column_value['tag_ids'] : [],
                        default => (string)$column_value['text'] != '' ? $column_value['text'] : null
                    };
                }

                $propertyAccessor->setValue($object, $config['name'], $value);


            }

            $propertyAccessor->setValue($object, $context['mondayId'], $item['id']);

            if (isset($context["title"])) {
                $propertyAccessor->setValue($object, $context["title"], $item['name']);
            }

            $processedObjects->add($object);

            $newMondayIds[$type][] = $item['id'];

            $this->entityManager->persist($object);

        }

        foreach ($afterOperations as $operation) {
            if ($operation['config']['type'] == 'related_same') {
                $matches = $existingObjects->filter(function (MondayItemInterface $value) use ($operation) {
                    $matchArray = array_map(function ($linkId) {
                        return $linkId['linkedPulseId'];
                    }, (array_values($operation['values']['linkedPulseIds'])));
                    return in_array($value->getMondayId(), $matchArray);
                });

                if (count($matches) > 0) {
                    $propertyAccessor->setValue($operation['object'], $operation['config']['name'], $matches);
                }

            } elseif ($operation['config']['type'] == 'board_relation_item') {
                $relatedObjects = [];

                foreach ($operation['values']['linkedPulseIds'] as $item) {
                    if ($operation['config']['unique']) {
                        $relatedObjects = (new $operation['config']['class'])->setMondayId($item['linkedPulseId']);
                    } else {
                        $relatedObjects[] = (new $operation['config']['class'])->setMondayId($item['linkedPulseId']);
                    }
                }

                $propertyAccessor->setValue($operation['object'], $operation['config']['name'], $relatedObjects);
            }
        }

        return ['objects' => $processedObjects, 'assetsQueue' => $assetIds, 'newMondayIds' => $newMondayIds];

    }
}
