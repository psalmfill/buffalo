<?php

namespace App\Http\Controllers\ReferenceData;

use App\Actions\ReferenceData\SaveReferenceValue;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReferenceValuePresenter;
use App\Http\Requests\ReferenceData\ReferenceValueRequest;
use App\Models\IncidentType;
use App\Models\ReferenceModel;
use App\Models\User;
use App\Support\DynamicFields\FieldType;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\ReferenceTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reference data pages for every lookup table. Values are listed, viewed,
 * created, edited, activated and deactivated; never deleted.
 */
class ReferenceDataController extends Controller
{
    public function home(): RedirectResponse
    {
        $this->authorize('viewAny', IncidentType::class);

        return redirect()->route('reference-data.index', ReferenceTable::IncidentTypes);
    }

    public function index(Request $request, ReferenceTable $table): Response
    {
        $this->authorize('viewAny', $table->modelClass());

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $values = $table->query()
            ->with(ReferenceValuePresenter::relations($table))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('code', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('reference-data/index', [
            'tables' => array_map(fn (ReferenceTable $case) => ReferenceValuePresenter::table($case), ReferenceTable::cases()),
            'table' => ReferenceValuePresenter::table($table),
            'columns' => ReferenceValuePresenter::columns($table),
            'values' => $values->map(fn (ReferenceModel $value) => ReferenceValuePresenter::row($value))->values(),
            'filters' => ['status' => $filters['status'] ?? '', 'search' => $filters['search'] ?? ''],
            'can' => ['manage' => $this->actor($request)->can('create', $table->modelClass())],
        ]);
    }

    public function show(Request $request, ReferenceTable $table, string $value, ReferenceUsage $usage): Response
    {
        $record = $this->find($table, $value);
        $this->authorize('view', $record);

        return Inertia::render('reference-data/show', [
            'table' => ReferenceValuePresenter::table($table),
            'columns' => ReferenceValuePresenter::columns($table),
            'value' => ReferenceValuePresenter::row($record),
            'schema' => $record instanceof IncidentType ? $this->schemaProps($record, $usage) : null,
            'can' => ['manage' => $this->actor($request)->can('update', $record)],
        ]);
    }

    public function create(ReferenceTable $table): Response
    {
        $this->authorize('create', $table->modelClass());

        return Inertia::render('reference-data/form', [
            'table' => ReferenceValuePresenter::table($table),
            'fields' => ReferenceValuePresenter::fields($table),
            'value' => null,
            'schema' => null,
        ]);
    }

    public function store(ReferenceValueRequest $request, ReferenceTable $table, SaveReferenceValue $save): RedirectResponse
    {
        $this->authorize('create', $table->modelClass());

        $record = $save->create($table, $request->columnValues(), $this->actor($request));

        // Incident types go straight to their detail fields.
        $route = $record instanceof IncidentType ? 'reference-data.edit' : 'reference-data.index';
        $parameters = $record instanceof IncidentType ? [$table, $record->ulid] : [$table];

        return redirect()->route($route, $parameters)
            ->with('toast', ['type' => 'success', 'message' => "{$table->singular()} \"{$record->name}\" created."]);
    }

    public function edit(ReferenceTable $table, string $value, ReferenceUsage $usage): Response
    {
        $record = $this->find($table, $value);
        $this->authorize('update', $record);

        return Inertia::render('reference-data/form', [
            'table' => ReferenceValuePresenter::table($table),
            'fields' => ReferenceValuePresenter::fields($table, $record),
            'value' => [...ReferenceValuePresenter::row($record), 'form' => ReferenceValuePresenter::formValues($record)],
            'schema' => $record instanceof IncidentType ? $this->schemaProps($record, $usage) : null,
        ]);
    }

    public function update(ReferenceValueRequest $request, ReferenceTable $table, string $value, SaveReferenceValue $save): RedirectResponse
    {
        $record = $this->find($table, $value);
        $this->authorize('update', $record);

        $save->update($record, $request->columnValues(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved.']);
    }

    public function activate(Request $request, ReferenceTable $table, string $value, SaveReferenceValue $save): RedirectResponse
    {
        return $this->setActive($request, $table, $value, true, $save);
    }

    public function deactivate(Request $request, ReferenceTable $table, string $value, SaveReferenceValue $save): RedirectResponse
    {
        return $this->setActive($request, $table, $value, false, $save);
    }

    private function setActive(Request $request, ReferenceTable $table, string $value, bool $active, SaveReferenceValue $save): RedirectResponse
    {
        $record = $this->find($table, $value);
        $this->authorize('update', $record);

        $save->setActive($record, $active, $this->actor($request));

        $message = $active
            ? "\"{$record->name}\" is active again."
            : "\"{$record->name}\" is deactivated: it stays on existing records but cannot be chosen for new ones.";

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaProps(IncidentType $type, ReferenceUsage $usage): array
    {
        return [
            'fields' => $type->details_schema,
            'version' => $type->schema_version,
            'inUse' => $usage->isInUse($type),
            'fieldTypes' => array_column(FieldType::cases(), 'value'),
        ];
    }

    private function find(ReferenceTable $table, string $ulid): ReferenceModel
    {
        /** @var ReferenceModel */
        return $table->query()
            ->with(ReferenceValuePresenter::relations($table))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
