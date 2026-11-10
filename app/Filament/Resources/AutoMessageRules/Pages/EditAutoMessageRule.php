<?php

namespace App\Filament\Resources\AutoMessageRules\Pages;

use App\Filament\Resources\AutoMessageRules\AutoMessageRuleResource;
use Filament\Resources\Pages\EditRecord;

class EditAutoMessageRule extends EditRecord
{
    protected static string $resource = AutoMessageRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * offset_days（符号付き）を UI 用の _offset_direction + _offset_days_abs に分解。
     * afterStateHydrated 内で $set を触ると初期表示で validation が走ることがあるため、
     * こちらの hook で事前に分解しておく。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $offset = (int) ($data['offset_days'] ?? 0);
        $data['_offset_direction'] = $offset < 0 ? 'before' : ($offset > 0 ? 'after' : 'same');
        $data['_offset_days_abs'] = $offset === 0 ? null : abs($offset);

        return $data;
    }
}
