@props(['id', 'label', 'autocomplete' => 'new-password'])
<div class="flex flex-col gap-2">
    <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}</label>
    <div class="relative">
        <input id="{{ $id }}" name="{{ $id }}" type="password" autocomplete="{{ $autocomplete }}" required class="field-control pr-28" aria-invalid="{{ $errors->has($id) ? 'true' : 'false' }}" @if ($errors->has($id)) aria-describedby="{{ $id }}-error" @endif>
        <button type="button" data-password-toggle="{{ $id }}" data-field-label="{{ $label }}" aria-controls="{{ $id }}" aria-pressed="false" aria-label="Tampilkan {{ $label }}" class="absolute inset-y-0 right-0 rounded-r-md px-3 text-xs text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring">Lihat</button>
    </div>
    @error($id)<p id="{{ $id }}-error" role="alert" class="text-sm text-destructive">{{ $message }}</p>@enderror
</div>
