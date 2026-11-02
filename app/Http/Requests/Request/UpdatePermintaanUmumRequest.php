<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePermintaanUmumRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $request = $this->route('request');

        return $user !== null
            && $request instanceof ModelsRequest
            && $request->type === 'umum'
            && $request->status === 'submitted'
            && $user->hasRole(UserRole::Admin, UserRole::PengelolaGudang);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', Rule::exists('items', 'item_id')->where('is_active', true)],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:999999'],
            'adjustment_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = trim((string) $this->input('adjustment_note'));
        $this->merge(['adjustment_note' => $note === '' ? null : $note]);
    }

    /**
     * Gudang mengubah permintaan orang lain, jadi setiap perubahan wajib
     * disertai alasan yang dibaca pemohon.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->changes() !== [] && $this->input('adjustment_note') === null) {
                $validator->errors()->add('adjustment_note', 'Tulis alasan perubahan supaya pemohon tahu mengapa permintaannya diubah.');
            }
        });
    }

    /**
     * Perubahan jumlah dibanding isi permintaan saat ini, per barang.
     * Barang yang dihapus tercatat menjadi 0, barang tambahan berawal dari 0.
     *
     * @return list<array{item_name: string, before: int, after: int}>
     */
    public function changes(): array
    {
        $before = $this->route('request')->item()->get()
            ->mapWithKeys(fn ($detail) => [$detail->item_id => (int) $detail->quantity_requested]);
        $after = collect($this->input('items', []))
            ->filter(fn (array $item): bool => (int) ($item['qty'] ?? 0) > 0)
            ->mapWithKeys(fn (array $item) => [$item['item_id'] => (int) $item['qty']]);

        $itemIds = $before->keys()->merge($after->keys())->unique()->values();
        $names = Item::query()->whereIn('item_id', $itemIds)->pluck('item_name', 'item_id');

        return $itemIds
            ->map(fn (string $itemId) => [
                'item_name' => $names[$itemId] ?? 'Barang',
                'before' => $before[$itemId] ?? 0,
                'after' => $after[$itemId] ?? 0,
            ])
            ->filter(fn (array $change) => $change['before'] !== $change['after'])
            ->values()
            ->all();
    }

    public function attributes(): array
    {
        return [
            'adjustment_note' => 'alasan perubahan',
        ];
    }
}
