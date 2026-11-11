<?php

namespace App\Domains\Projects\Livewire\Admin\Projects;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantProjectCreation;
use App\Domains\Accounting\Models\AccountingCode;
use App\Domains\Addresses\Models\Address;
use App\Domains\Clients\Models\Client;
use App\Domains\Payroll\Models\PayRateType;
use App\Domains\Projects\Enums\ProjectStatusEnum;
use App\Domains\Projects\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Project Form')]
class Form extends Component
{
    use AuthorizesRequests;

    public ?Project $project = null;

    public bool $isEdit = false;

    public string $name = '';

    public ?string $project_number = null;

    public ?string $accounting_code_id = null;

    public ?string $description = null;

    public ?string $budget = null;

    public string $status = 'pending';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $client_id = null;

    public ?string $address_id = null;

    public ?string $leave_category = null;

    public ?string $pay_rate_type_id = null;

    public bool $is_active = true;

    public function mount(?Project $project = null): void
    {
        if ($project !== null && $project->exists) {
            $this->authorize('update', $project);

            $this->project = $project;
            $this->isEdit = true;
            $this->name = $project->name;
            $this->project_number = $project->project_number;
            $this->accounting_code_id = $project->accounting_code_id;
            $this->description = $project->description;
            $this->budget = $project->budget !== null ? (string) $project->budget : null;
            $this->status = $project->status?->value ?? 'pending';
            $this->start_date = $project->start_date?->format('Y-m-d');
            $this->end_date = $project->end_date?->format('Y-m-d');
            $this->client_id = $project->client_id;
            $this->address_id = $project->address_id;
            $this->leave_category = $project->leave_category;
            $this->pay_rate_type_id = $project->pay_rate_type_id;
            $this->is_active = (bool) $project->is_active;

            return;
        }

        $this->authorize('create', Project::class);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'project_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('projects', 'project_number')->ignore($this->project?->id),
            ],
            'accounting_code_id' => ['nullable', 'exists:accounting_codes,id'],
            'description' => ['nullable', 'string'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:'.implode(',', array_keys(ProjectStatusEnum::toArray()))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'address_id' => ['nullable', 'exists:addresses,id'],
            'leave_category' => ['nullable', Rule::in(['sick', 'vacation'])],
            'pay_rate_type_id' => ['nullable', 'exists:pay_rate_types,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        if ($this->leave_category === '') {
            $this->leave_category = null;
        }

        if ($this->accounting_code_id === '') {
            $this->accounting_code_id = null;
        }

        if ($this->budget === '') {
            $this->budget = null;
        }

        $validated = $this->validate();

        $resolvedAccountingCode = null;
        if (filled($validated['accounting_code_id'] ?? null)) {
            $resolvedAccountingCode = AccountingCode::query()
                ->whereKey($validated['accounting_code_id'])
                ->value('code');
        }

        $validated['accounting_code'] = $resolvedAccountingCode;

        if ($this->isEdit) {
            $project = $this->project;
            if ($project === null) {
                return;
            }

            $this->authorize('update', $project);
            $project->update($validated);

            session()->flash('success', 'Project updated successfully.');
            $this->redirectRoute('admin.projects.index', navigate: true);

            return;
        }

        $this->authorize('create', Project::class);

        // SaaS tenancy (§1): resolves the org from context, enforces the
        // trial 1-project cap, and grants the creator project-admin access.
        // No-op (plain create) when SAAS_MODE is off. Aborts 403 when the
        // user has no org, or the trial cap is reached.
        $creator = Auth::user();
        abort_unless($creator instanceof User, 401);

        app(TenantProjectCreation::class)->create($creator, $validated);

        session()->flash('success', 'Project created successfully.');

        $this->redirectRoute('admin.projects.index', navigate: true);
    }

    #[On('client-inline-created')]
    public function setClientFromWidget(string $clientId): void
    {
        $this->client_id = $clientId;
        $this->address_id = null;
    }

    #[On('address-inline-created')]
    public function setAddressFromWidget(string $addressId): void
    {
        $this->address_id = $addressId;
    }

    public function render()
    {
        $clientId = $this->client_id;
        $selectedAddressId = $this->address_id;

        return view('projects::livewire.admin.projects.form', [
            'statuses' => ProjectStatusEnum::toArray(),
            'clients' => Client::query()->orderBy('company_name')->get(['id', 'company_name']),
            'accountingCodes' => AccountingCode::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'payRateTypes' => PayRateType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
            'addresses' => Address::query()
                ->when($clientId || $selectedAddressId, function ($query) use ($clientId, $selectedAddressId): void {
                    $query->where(function ($addressQuery) use ($clientId, $selectedAddressId): void {
                        if (filled($clientId)) {
                            $addressQuery->where('client_id', $clientId)
                                ->orWhereNull('client_id');
                        }

                        if (filled($selectedAddressId)) {
                            $addressQuery->orWhere('id', $selectedAddressId);
                        }
                    });
                })
                ->orderBy('address1')
                ->get(['id', 'address1', 'city', 'state', 'client_id']),
        ]);
    }
}
