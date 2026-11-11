<?php

namespace App\Domains\Clients\Livewire\Admin\Clients;

use App\Core\Tenancy\Services\TenantContext;
use App\Domains\Addresses\Models\Address;
use App\Domains\Clients\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('clients::livewire.layouts.client-management-admin')]
#[Title('Client Form')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?Client $client = null;

    public bool $isEdit = false;

    public string $company_name = '';

    public ?string $contact_name = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $notes = null;

    public bool $is_active = true;

    /**
     * @var array<int, array{id:string|null,address1:string,address2:string,city:string,state:string,zip:string,country:string}>
     */
    public array $addresses = [];

    public function mount(?Client $client = null): void
    {
        if ($client !== null && $client->exists) {
            $this->authorize('update', $client);

            $this->client = $client;
            $this->isEdit = true;
            $this->company_name = $client->company_name;
            $this->contact_name = $client->contact_name;
            $this->email = $client->email;
            $this->phone = $client->phone;
            $this->mobile = $client->mobile;
            $this->notes = $client->notes;
            $this->is_active = (bool) $client->is_active;
            $this->addresses = $client->addresses()
                ->orderBy('address1')
                ->get(['id', 'address1', 'address2', 'city', 'state', 'zip', 'country'])
                ->map(function (Address $address): array {
                    return [
                        'id' => (string) $address->id,
                        'address1' => (string) $address->address1,
                        'address2' => (string) ($address->address2 ?? ''),
                        'city' => (string) ($address->city ?? ''),
                        'state' => (string) ($address->state ?? ''),
                        'zip' => (string) ($address->zip ?? ''),
                        'country' => (string) ($address->country ?? 'US'),
                    ];
                })
                ->values()
                ->all();

            if ($this->addresses === []) {
                $this->addresses = [$this->emptyAddressRow()];
            }

            return;
        }

        $this->authorize('create', Client::class);
        $this->addresses = [$this->emptyAddressRow()];
    }

    protected function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();
        $addressesPayload = $this->normalizedAddresses();
        $this->validateAddresses($addressesPayload);

        if ($this->isEdit) {
            $client = $this->client;
            if ($client === null) {
                return;
            }

            $this->authorize('update', $client);

            DB::transaction(function () use ($client, $validated, $addressesPayload): void {
                $client->update($validated);
                $this->syncClientAddresses($client, $addressesPayload);
            });

            session()->flash('success', 'Client updated successfully.');
        } else {
            $this->authorize('create', Client::class);

            // SaaS tenancy (§1): new clients belong to the caller's current
            // org when one is set; legacy (null) when SaaS mode is off.
            $context = app(TenantContext::class);
            $validated['organization_id'] = $context->isEnabled()
                ? $context->currentOrganizationId()
                : null;

            DB::transaction(function () use ($validated, $addressesPayload): void {
                $client = Client::query()->create($validated);
                $this->syncClientAddresses($client, $addressesPayload);
            });

            session()->flash('success', 'Client created successfully.');
        }

        $this->redirectRoute('admin.clients.index', navigate: true);
    }

    public function addAddressRow(): void
    {
        $this->addresses[] = $this->emptyAddressRow();
    }

    public function removeAddressRow(int $index): void
    {
        if (! isset($this->addresses[$index])) {
            return;
        }

        unset($this->addresses[$index]);
        $this->addresses = array_values($this->addresses);

        if ($this->addresses === []) {
            $this->addresses[] = $this->emptyAddressRow();
        }
    }

    /**
     * @return array{id:null,address1:string,address2:string,city:string,state:string,zip:string,country:string}
     */
    private function emptyAddressRow(): array
    {
        return [
            'id' => null,
            'address1' => '',
            'address2' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'country' => 'US',
        ];
    }

    /**
     * @return array<int, array{id:string|null,address1:string,address2:string,city:string,state:string,zip:string,country:string}>
     */
    private function normalizedAddresses(): array
    {
        return collect($this->addresses)
            ->map(function (array $row): array {
                return [
                    'id' => filled($row['id'] ?? null) ? (string) $row['id'] : null,
                    'address1' => trim((string) ($row['address1'] ?? '')),
                    'address2' => trim((string) ($row['address2'] ?? '')),
                    'city' => trim((string) ($row['city'] ?? '')),
                    'state' => trim((string) ($row['state'] ?? '')),
                    'zip' => trim((string) ($row['zip'] ?? '')),
                    'country' => strtoupper(trim((string) ($row['country'] ?? 'US'))),
                ];
            })
            ->filter(function (array $row): bool {
                return collect(Arr::except($row, ['id']))
                    ->contains(fn (string $value): bool => $value !== '');
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id:string|null,address1:string,address2:string,city:string,state:string,zip:string,country:string}>  $addressesPayload
     */
    private function validateAddresses(array $addressesPayload): void
    {
        Validator::make([
            'addresses' => $addressesPayload,
        ], [
            'addresses' => ['array'],
            'addresses.*.id' => ['nullable', 'string', 'exists:addresses,id'],
            'addresses.*.address1' => ['required', 'string', 'max:255'],
            'addresses.*.address2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.state' => ['nullable', 'string', 'max:255'],
            'addresses.*.zip' => ['nullable', 'string', 'max:50'],
            'addresses.*.country' => ['required', 'string', 'max:10'],
        ])->validate();
    }

    /**
     * @param  array<int, array{id:string|null,address1:string,address2:string,city:string,state:string,zip:string,country:string}>  $addressesPayload
     */
    private function syncClientAddresses(Client $client, array $addressesPayload): void
    {
        $persistedAddressIds = [];

        foreach ($addressesPayload as $addressPayload) {
            $addressId = $addressPayload['id'];

            $payload = Arr::except($addressPayload, ['id']);
            $payload['client_id'] = (string) $client->id;

            if ($addressId !== null) {
                $existingAddress = $client->addresses()->whereKey($addressId)->first();

                if ($existingAddress !== null) {
                    $existingAddress->update($payload);
                    $persistedAddressIds[] = (string) $existingAddress->id;

                    continue;
                }
            }

            $createdAddress = $client->addresses()->create($payload);
            $persistedAddressIds[] = (string) $createdAddress->id;
        }

        $client->addresses()
            ->whereNotIn('id', $persistedAddressIds)
            ->delete();
    }

    public function render()
    {
        return view('clients::livewire.admin.clients.form');
    }
}
