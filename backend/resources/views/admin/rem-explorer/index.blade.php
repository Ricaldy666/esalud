@extends('layouts.admin')
@section('title', 'Estructuras REM')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Explorador de Estructuras REM</h1>
        <p class="text-sm text-gray-500 mt-1">Estructuras detectadas por el Parser REM automático</p>
    </div>
    <div class="text-xs text-gray-400 bg-gray-100 px-3 py-1 rounded-full">
        {{ count($structures) }} estructuras
    </div>
</div>

@if ($structures->isEmpty())
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
        <p class="text-yellow-700 font-medium">No hay estructuras detectadas</p>
        <p class="text-yellow-600 text-sm mt-1">Usa <code class="bg-yellow-100 px-1 rounded">php artisan rem:parse-persist</code> para crear una.</p>
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">ID</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Año</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Serie</th>
                    <th class="px-4 py-3 text-center font-semibold text-gray-600 text-xs uppercase tracking-wider">Versión</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Estado</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Hash</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Archivo</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">Creado</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($structures as $s)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 font-mono text-xs text-gray-400">{{ $s->id }}</td>
                    <td class="px-4 py-3 font-semibold">{{ $s->anio }}</td>
                    <td class="px-4 py-3">
                        <span class="font-mono font-bold text-indigo-600">{{ $s->serie }}</span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-xs font-bold">
                            {{ $s->version_number }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $colors = ['draft' => 'bg-yellow-100 text-yellow-700', 'approved' => 'bg-blue-100 text-blue-700', 'active' => 'bg-green-100 text-green-700', 'superseded' => 'bg-gray-100 text-gray-500', 'deleted' => 'bg-red-50 text-red-400'];
                            $label = $s->trashed() ? 'deleted' : $s->status;
                        @endphp
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$label] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ $label }}
                        </span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400 max-w-[120px] truncate" title="{{ $s->hash_estructura }}">
                        {{ substr($s->hash_estructura, 0, 12) }}…
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate" title="{{ $s->source_filename }}">
                        {{ $s->source_filename ?? '-' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        {{ $s->created_at ? $s->created_at->format('d/m/Y H:i') : '-' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/rem-explorer/{{ $s->id }}"
                           class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                            Ver &rarr;
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
