<?php

use Service\MondayService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class MondayWorkDocDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
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
        return array_key_exists('fileType', $context) && $context['fileType'] == 'MONDAY_DOC' && array_key_exists('material', $context) && $context['material'] !== null;
    }

    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): ArrayCollection
    {
        $repository = $this->entityManager->getRepository($type);
        $existingObjects = new ArrayCollection($repository->findAll());
        $processedObjects = new ArrayCollection();
        $columns = $context['columns'];
        $propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->getPropertyAccessor();

        foreach ($data as $block) {
            $object = $existingObjects->findFirst(function (int $key, $value) use ($block) {
                return $value->getMondayId() == $block['id'];
            });

            if (null === $object) {
                $object = new $type();
            }
            foreach ($block as $key => $value){
                if($key === 'content'){
                    $value = json_decode($value, true);
                }
                $propertyAccessor->setValue($object, $columns[$key]['name'], $value);
            }

            $propertyAccessor->setValue($object, $columns['material']['name'], $context['material']);

            $this->entityManager->persist($object);

            $processedObjects->add($block);
        }

        return $processedObjects;
    }
}
