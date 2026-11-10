<?php

namespace App\Filament\Resources\GuestRegistrationTokens\Pages;

use App\Filament\Resources\GuestRegistrationTokens\GuestRegistrationTokenResource;
use Filament\Resources\Pages\ListRecords;

class ListGuestRegistrationTokens extends ListRecords
{
    protected static string $resource = GuestRegistrationTokenResource::class;
}
