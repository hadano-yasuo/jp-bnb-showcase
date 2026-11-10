<?php

namespace App\Filament\Resources\AutoMessageRules\Pages;

use App\Filament\Resources\AutoMessageRules\AutoMessageRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAutoMessageRule extends CreateRecord
{
    protected static string $resource = AutoMessageRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
