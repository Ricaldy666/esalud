@extends('layouts.admin')
@section('title', "{$structure->anio}/{$structure->serie} v{$structure->version_number}")

@section('content')
<div class="mb-6">
    <a href="/admin/rem-explorer" class="text-sm text-indigo-600 hover:text-indigo-800">&larr; Volver al listado</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                {{ $structure->anio }}/<span class="text-indigo-600">{{ $structure->serie }}</span>
                <span class="text-lg text-gray-400 font-normal">v{{ $structure->version_number }}</span>
            </h1>
            <p class="text-sm text-gray-500 mt-1">ID: {{ $structure->id }} &middot; {{ $structure->hash_estructura }}</p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $colors = ['draft' => 'bg-yellow-100 text-yellow-700', 'approved' => 'bg-blue-100 text-blue-700', 'active' => 'bg-green-100 text-green-700', 'superseded' => 'bg-gray-100 text-gray-500'];
                $label = $structure->trashed() ? 'deleted' : $structure->status;
            @endphp
            <span class="inline-block px-3 py-1 rounded-full text-xs font-medium {{ $colors[$label] ?? 'bg-gray-100 text-gray-600' }}">
                {{ $label }}
            </span>
            @if ($structure->source_filename)
                <span class="text-xs text-gray-400">{{ $structure->source_filename }}</span>
            @endif
        </div>
    </div>

    @if ($structure->notes)
        <div class="mt-3 bg-gray-50 rounded-lg p-3 text-sm text-gray-600">
            <span class="font-medium">Notas:</span> {{ $structure->notes }}
        </div>
    @endif
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php $fields = [
        ['Formularios', $stats['forms'], 'bg-blue-50 text-blue-700'],
        ['Secciones', $stats['sections'], 'bg-purple-50 text-purple-700'],
        ['Campos', $stats['fields'], 'bg-gray-100 text-gray-700'],
        ['Reglas SUM', $stats['sum_equals'], 'bg-amber-50 text-amber-700'],
        ['Reglas Required', $stats['required'], 'bg-rose-50 text-rose-700'],
        ['Control Oculto', $stats['control_oculto'], 'bg-teal-50 text-teal-600'],
        ['Total reglas', $stats['sum_equals'] + $stats['required'], 'bg-indigo-50 text-indigo-700'],
        ['Versión', "v{$structure->version_number}", 'bg-green-50 text-green-700'],
    ]; @endphp
    @foreach ($fields as [$label, $value, $color])
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold {{ explode(' ', $color)[1] }}">{{ $value }}</div>
        <div class="text-xs text-gray-500 mt-1">{{ $label }}</div>
    </div>
    @endforeach
</div>

<div x-data="{ tab: 'forms' }" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="border-b border-gray-200">
        <nav class="flex -mb-px">
            <button @click="tab = 'forms'" :class="tab === 'forms' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-6 py-3 text-sm font-medium border-b-2 transition-colors">Formularios / Secciones / Campos</button>
            <button @click="tab = 'json'" :class="tab === 'json' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-6 py-3 text-sm font-medium border-b-2 transition-colors">JSON Original</button>
        </nav>
    </div>

    <div x-show="tab === 'forms'" class="p-6">
        @forelse ($est['forms'] ?? [] as $form)
        <div x-data="{ open: false }" class="mb-4 last:mb-0">
            <button @click="open = !open"
                    class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-left">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-gray-900 font-mono">{{ $form['sheetName'] }}</span>
                    <span class="text-xs text-gray-400">
                        {{ count($form['sections'] ?? []) }} seccion(es)
                    </span>
                </div>
                <svg x-show="!open" class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                <svg x-show="open" class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
            </button>
            <div x-show="open" x-transition class="mt-2 space-y-2">
                @forelse ($form['sections'] ?? [] as $section)
                <div x-data="{ secOpen: false }" class="ml-4 border-l-2 border-indigo-200 pl-4">
                    <button @click="secOpen = !secOpen"
                            class="w-full flex items-center justify-between px-3 py-2 hover:bg-indigo-50 rounded-md transition-colors text-left">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-indigo-700 font-mono">
                                {{ $section['codigo'] ?? 'IMPLICITA' }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $section['titulo'] ?? '' }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-400">
                            <span>{{ count($section['fields'] ?? []) }} campos</span>
                            <span>filas {{ $section['filaInicioDatos'] }}-{{ $section['filaFinDatos'] ?? '?' }}</span>
                            <svg x-show="!secOpen" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            <svg x-show="secOpen" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                        </div>
                    </button>
                    <div x-show="secOpen" x-transition class="mt-1">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-gray-400 uppercase tracking-wider">
                                    <th class="px-2 py-1 text-left font-mono">Letra</th>
                                    <th class="px-2 py-1 text-left">Label</th>
                                    <th class="px-2 py-1 text-center">Total</th>
                                    <th class="px-2 py-1 text-center">Control</th>
                                    <th class="px-2 py-1 text-left">Regla</th>
                                    <th class="px-2 py-1 text-left">Columnas Origen</th>
                                    <th class="px-2 py-1 text-left">Col Destino</th>
                                    <th class="px-2 py-1 text-left">Rango Filas</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($section['fields'] ?? [] as $field)
                                @php
                                    $regla = $field['reglaDetectada'] ?? null;
                                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : ($regla ?? '');
                                    $colsOrigen = is_array($regla) ? implode(', ', array_slice($regla['columnasOrigen'] ?? [], 0, 8)) : '';
                                    $colDestino = is_array($regla) ? ($regla['columnaDestino'] ?? '') : '';
                                    $rangoFilas = is_array($regla) ? ($regla['rangoFilas'] ?? '') : '';
                                    $ruleColors = ['sum_equals' => 'text-amber-600 bg-amber-50', 'required_and_le_parent' => 'text-rose-600 bg-rose-50', 'control_oculto' => 'text-teal-600 bg-teal-50'];
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 py-1.5 font-mono font-bold text-gray-700">{{ $field['letra'] }}</td>
                                    <td class="px-2 py-1.5 text-gray-600 max-w-[200px] truncate" title="{{ $field['label'] }}">{{ $field['label'] }}</td>
                                    <td class="px-2 py-1.5 text-center">
                                        @if ($field['esTotal'] ?? false)
                                            <span class="inline-block w-2 h-2 rounded-full bg-green-400" title="Total"></span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        @if ($field['esControlOculto'] ?? false)
                                            <span class="inline-block w-2 h-2 rounded-full bg-teal-400" title="Control oculto"></span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5">
                                        @if ($tipo)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium {{ $ruleColors[$tipo] ?? 'text-gray-500' }}">
                                                {{ $tipo }}
                                            </span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 font-mono text-gray-500 max-w-[240px] truncate" title="{{ $colsOrigen }}">{{ $colsOrigen ?: '—' }}</td>
                                    <td class="px-2 py-1.5 font-mono text-gray-500">{{ $colDestino ?: '—' }}</td>
                                    <td class="px-2 py-1.5 font-mono text-gray-500">{{ $rangoFilas ?: '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @empty
                    <p class="ml-4 text-sm text-gray-400">Sin secciones</p>
                @endforelse
            </div>
        </div>
        @empty
            <p class="text-sm text-gray-400">Sin formularios</p>
        @endforelse
    </div>

    <div x-show="tab === 'json'" class="p-6">
        <pre class="bg-gray-50 rounded-lg p-4 overflow-auto max-h-[600px] text-xs leading-relaxed"><code>{{ json_encode($structure->estructura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
@endsection
