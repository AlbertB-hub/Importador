<?php

use App\Docs\Document\Enum\FileBucketMetadataType;
use App\Docs\Document\FileBucket;
use App\Docs\Document\FileBucketMetadata;
use App\Docs\Service\FileBucketService;
use App\Message\MondayItemMessage;
use Monday\Service\MondayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class MondayItemMessageHandler
{
    function __construct(
        private readonly HttpClientInterface    $client,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentManager        $documentManager,
        private readonly FileBucketService      $bucketService,
        private readonly MondayService          $mondayService
    )
    {
    }

    public function __invoke(MondayItemMessage $message)
    {
        $itemId = $message->getMondayId();
        $data = $message->getData();
        $type = $message->getItemClass();
        unset($data['type']);

        foreach ($data as $key => $assets) {
            $assetResponse = $this->mondayService->getAssetById($assets)['assets'];

            foreach ($assetResponse as $asset) {
                $file = $this->client->request('GET', ($asset['public_url']));

                $tempFile = tmpfile();

                foreach ($this->client->stream($file) as $chunk) {
                    fwrite($tempFile, $chunk->getContent());
                }

                $metadata = (new FileBucketMetadata());
                $metadata->setPublic(false);

                $documentId = $this->bucketService->create(stream_get_meta_data($tempFile)['uri'], FileBucketMetadataType::MONDAY_ASSET, $metadata, $asset['name'])->getId();

                $document = $this->documentManager->getRepository(FileBucket::class)->find($documentId);
                
                //Vincular item de la relacional con el archivo adjunto importado
                $mondayItem = $this->entityManager->getRepository($type)->findOneBy(['mondayId' => $itemId]);

                $propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()->getPropertyAccessor();

                $mondayItemFileArray = $propertyAccessor->getValue($mondayItem, $key);

                $mondayItemFileArray[] = $document->getId();

                $propertyAccessor->setValue($mondayItem, $key, $mondayItemFileArray);

                $this->entityManager->persist($mondayItem);
            }
        }

        $this->entityManager->flush();
    }
}
