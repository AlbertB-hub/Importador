<?php

namespace App\Message;

class MondayItemMessage
{

    public function __construct(
        private readonly int  $mondayId,
        private readonly string $itemClass,
        private readonly array  $data
    )
    {

    }

    public function getMondayId(): int
    {
        return $this->mondayId;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getItemClass(): string
    {
        return $this->itemClass;
    }
}