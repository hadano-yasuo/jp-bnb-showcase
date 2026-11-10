<?php

namespace App\Filament\Resources\GuestChannels\Pages;

use App\Filament\Resources\GuestChannels\GuestChannelResource;
use Filament\Resources\Pages\ListRecords;

class ListGuestChannels extends ListRecords
{
    protected static string $resource = GuestChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
