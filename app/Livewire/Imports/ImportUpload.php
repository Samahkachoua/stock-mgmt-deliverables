<?php

namespace App\Livewire\Imports;

use App\Imports\ImportEngine;
use App\Imports\ImportRefused;
use App\Imports\Imports;
use App\Imports\ImportType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Step 1 of an import: download the template, then upload the filled-in .xlsx.
 */
#[Layout('components.layouts.app')]
class ImportUpload extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $type = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(string $type): void
    {
        $import = Imports::get($type);
        abort_unless($import->allows($this->user()), 403);
        $this->type = $import->slug();
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file');
    }

    /**
     * Not named upload(): in the browser $wire.upload is Livewire's own file-upload
     * function, so wire:submit="upload" would call that instead of this action.
     */
    public function send(): void
    {
        $this->validate();

        try {
            $job = app(ImportEngine::class)->upload($this->import(), $this->file, $this->user());
        } catch (ImportRefused $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->redirectRoute('imports.show', $job->id, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.imports.upload', ['import' => $this->import()])->title($this->import()->title());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return ['file' => ['required', 'file', 'extensions:xlsx', 'max:'.config('imports.max_kilobytes')]];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'file.required' => __('imports.upload.choose_file'),
            'file.file' => __('imports.errors.not_xlsx'),
            'file.extensions' => __('imports.errors.not_xlsx'),
            'file.max' => __('imports.errors.too_large', ['max' => (int) (config('imports.max_kilobytes') / 1024)]),
        ];
    }

    private function import(): ImportType
    {
        return Imports::get($this->type);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
