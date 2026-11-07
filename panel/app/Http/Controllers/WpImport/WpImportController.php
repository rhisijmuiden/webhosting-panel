<?php

namespace App\Http\Controllers\WpImport;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WpImport;
use App\Services\WpImport\WpImportException;
use App\Services\WpImport\WpImportManager;
use App\Services\WpImport\WpImportOffsetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The WordPress import wizard, shared by admins and customers; the subclasses decide whose websites. */
abstract class WpImportController extends Controller
{
    /** 'admin' or 'client': route names and layout. */
    protected string $area;

    public function __construct(protected readonly WpImportManager $imports) {}

    /** The customer whose websites this user may import into (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.wp-import.{$name}", $params);
    }

    protected function websites(Request $request)
    {
        // Team members only see the websites they were given (Website global scope).
        return Website::query()->where('status', 'active')->with('customer')->orderBy('domain')
            ->when($this->customerId($request), fn ($q, $id) => $q->where('customer_id', $id));
    }

    protected function authorizeImport(Request $request, WpImport $import): void
    {
        $id = $this->customerId($request);
        abort_if($import->website === null, 404);
        abort_if($id !== null && ($import->customer_id !== $id || ! Website::whereKey($import->website_id)->exists()), 404);
    }

    public function index(Request $request): View
    {
        $websites = $this->websites($request)->get();
        $imports = WpImport::with('website')->whereIn('website_id', $websites->pluck('id'))->latest('id')->limit(30)->get();

        return view('wp-import.index', [
            'area' => $this->area, 'websites' => $websites, 'imports' => $imports,
            'selected' => (int) $request->query('website', $websites->count() === 1 ? $websites->first()->id : 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['website' => ['required', 'integer']]);
        $website = $this->websites($request)->findOrFail($data['website']);
        try {
            $import = $this->imports->start($website, $request->user()->id);
        } catch (WpImportException $e) {
            return back()->withErrors(['website' => $e->getMessage()])->withInput();
        }

        return redirect($this->route('show', $import));
    }

    public function show(Request $request, WpImport $wpImport): View
    {
        $this->authorizeImport($request, $wpImport);
        $this->imports->sync($wpImport);
        $wpImport->refresh();

        return view('wp-import.show', [
            'area' => $this->area, 'import' => $wpImport, 'website' => $wpImport->website,
            'chunk' => WpImportManager::CHUNK, 'maxBytes' => WpImportManager::maxBytes(),
        ]);
    }

    public function uploadStart(Request $request, WpImport $wpImport, string $kind): JsonResponse
    {
        $this->authorizeImport($request, $wpImport);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1']]);
        try {
            $this->imports->startUpload($wpImport, $kind, $data['name'], (int) $data['size']);
        } catch (WpImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['chunk_size' => WpImportManager::CHUNK, 'received' => 0]);
    }

    /** One chunk of an upload; ?offset= must match what was received so far. */
    public function uploadChunk(Request $request, WpImport $wpImport, string $kind): JsonResponse
    {
        $this->authorizeImport($request, $wpImport);
        try {
            $have = $this->imports->chunk($wpImport, $kind, (int) $request->query('offset', -1), $request->getContent(true));
        } catch (WpImportOffsetException $e) {
            return response()->json(['message' => $e->getMessage(), 'received' => $e->received], 409);
        } catch (WpImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['received' => $have, 'complete' => $wpImport->fresh()->uploaded($kind)]);
    }

    public function check(Request $request, WpImport $wpImport): RedirectResponse
    {
        $this->authorizeImport($request, $wpImport);
        try {
            $this->imports->check($wpImport);
        } catch (WpImportException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect($this->route('show', $wpImport));
    }

    public function run(Request $request, WpImport $wpImport): RedirectResponse
    {
        $this->authorizeImport($request, $wpImport);
        $report = $wpImport->report ?? [];
        $data = $request->validate([
            'new_url' => ['required', 'string', Rule::in($report['target']['urls'] ?? [])],
            'urls' => ['array', 'max:40'], 'urls.*' => ['string', 'max:1000'],
            'paths' => ['array', 'max:20'], 'paths.*' => ['string', 'max:1000'],
            'disable' => ['array', 'max:20'], 'disable.*' => ['string', 'max:1000'],
            'extra_old' => ['nullable', 'string', 'min:4', 'max:500', 'different:extra_new', 'not_regex:/[\r\n]/'],
            'extra_new' => ['nullable', 'required_with:extra_old', 'string', 'max:500', 'not_regex:/[\r\n]/'],
            'replace' => [empty($report['target']['content']) ? 'nullable' : 'accepted'],
            'confirm' => ['accepted'],
        ], [
            'replace.accepted' => __('Confirm that the current files of :domain may be replaced.', ['domain' => $wpImport->domain]),
            'confirm.accepted' => __('Confirm the changes first.'),
        ]);
        $options = [
            'replace' => (bool) ($data['replace'] ?? false), 'new_url' => $data['new_url'],
            'urls' => $data['urls'] ?? [], 'paths' => $data['paths'] ?? [], 'disable' => $data['disable'] ?? [],
            'extra' => filled($data['extra_old'] ?? null) ? [[trim($data['extra_old']), trim((string) $data['extra_new'])]] : [],
        ];
        try {
            $this->imports->run($wpImport, $options, $request->user()->id);
        } catch (WpImportException $e) {
            return back()->withErrors(['import' => $e->getMessage()])->withInput();
        }

        return redirect($this->route('show', $wpImport));
    }

    public function reset(Request $request, WpImport $wpImport): RedirectResponse
    {
        $this->authorizeImport($request, $wpImport);
        try {
            $this->imports->reset($wpImport);
        } catch (WpImportException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect($this->route('show', $wpImport));
    }

    public function destroy(Request $request, WpImport $wpImport): RedirectResponse
    {
        $this->authorizeImport($request, $wpImport);
        if ($wpImport->busy()) {
            return back()->withErrors(['import' => __('This step is not possible now.')]);
        }
        $this->imports->discard($wpImport);

        return redirect($this->route('index'))->with('status', __('The import was removed.'));
    }
}
