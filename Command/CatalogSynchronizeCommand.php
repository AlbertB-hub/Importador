<?php

use App\Message\MondayItemMessage;
use Serializer\MondayItemInterface;
use Service\MondayService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[AsCommand(
    name: 'importar',
    description: 'Add a short description for your command',
)]
class CatalogSynchronizeCommand extends Command
{

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface    $message,
        private readonly ParameterBagInterface  $parameterBag,
        private readonly DenormalizerInterface  $denormalizer,
        private readonly MondayService          $mondayService,
        string                                  $name = null
    )
    {
        parent::__construct($name);
    }

    protected
    function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->importDigicomps();


        return Command::SUCCESS;
    }

    private function importDigicomps(): void
    {
        // Obtener configuración para el importador
        $boards = (array)$this->parameterBag->get('app.monday.boards.import');

        $propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->getPropertyAccessor();

        // Importar todos los items
        $importItems = [];
        foreach ($boards as $itemType => $boardConfig) {
            $importItems[$itemType]['config'] = $boardConfig;

            $importItems[$itemType]['items'] = $this->importBoardItems((array)$this->parameterBag->get($boardConfig['board']));
        }

        // Relacionar items entre sí
        foreach ($importItems as $items) {
            if (!$items['config']['relations']) {
                continue;
            }

            foreach ($items['items']['objects'] as $item) {
                foreach ($items['config']['relations'] as $relation) {
                    switch ($relation['relation_type']) {
                        case 'one':
                            $this->relateOneItem($item, $importItems[$relation['item_type']]['items']['objects'], $relation['property'], $propertyAccessor);
                            break;
                        case 'many':
                            $this->relateManyToManyItems($item, $importItems[$relation['item_type']]['items']['objects'], $relation['property'], $propertyAccessor);
                            break;
                    }
                }
            }
        }

       $this->entityManager->flush();

        // Borrar items que ya no están en Monday
        foreach ($importItems as $items) {
            foreach (array_keys($items['items']['newMondayIds']) as $class) {
                $eraseItems = [];
                $itemRepository = $this->entityManager->getRepository($class);
                $existingItems = $itemRepository->findAllGetMondayId();
                foreach ($existingItems as $item) {
                    if (!in_array($item['mondayId'], $items['items']['newMondayIds'][$class])) {
                        $eraseItems[] = $item['mondayId'];
                    }
                }

                foreach ($eraseItems as $item) {
                    $object = $itemRepository->findOneBy(['mondayId' => $item]);
                    $propertyAccessor->setValue($object, 'erased', true);

                    $this->entityManager->persist($object);
                }
            }
        }

        $this->entityManager->flush();

        // Crear colas para importar archivos adjuntos
        foreach ($importItems as $items) {
            if(!empty($items['items']['assetsQueue'])) {
                foreach ($items['items']['assetsQueue'] as $key => $assets) {
                    $this->message->dispatch(new MondayItemMessage($key, $assets['itemType'], $assets));
                }
            }
        }
    }

    private function importBoardItems(array $config)
    {
        $data = $this->mondayService->getItemsByBoardId($config['id']);

        return $this->denormalizer->denormalize($data, $config['class'], null, $config['context']);
    }

    private function relateOneItem(MondayItemInterface $mainItem, ArrayCollection $relatedItems, string $property, PropertyAccessorInterface $propertyAccessor): void
    {
        $fullItem = $relatedItems->filter(function (MondayItemInterface $relatedItem) use ($mainItem, $property, $propertyAccessor) {
            return $relatedItem->getMondayId() == $propertyAccessor->getValue($mainItem, $property)?->getMondayId();
        })->first();

        if ($fullItem) {
            $propertyAccessor->setValue($mainItem, $property, $fullItem);
        }
    }

    private function relateManyToManyItems(MondayItemInterface $mainItem, ArrayCollection $relatedItems, string $property, PropertyAccessorInterface $propertyAccessor): void
    {
        foreach ($propertyAccessor->getValue($mainItem, $property) as $relatedTempItem) {
            $propertyAccessor->setValue($mainItem, $property, $relatedItems->filter(function (MondayItemInterface $item) use ($relatedTempItem) {
                return $item->getMondayId() == $relatedTempItem->getMondayId();
            }));
        }
    }
}
