<?php

namespace App\Http\Controllers;

use App\Domain\RemParser\Services\RemParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RemParserTestController extends Controller
{
    public function test(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xlsm,xls', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'message' => 'Error de validacion',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $tempPath = storage_path('app/tmp/remparser_' . Str::random(24) . '.' . $file->extension());

        try {
            $file->move(dirname($tempPath), basename($tempPath));

            $parser = app(RemParserService::class);
            $result = $parser->parse($tempPath);

            return response()->json([
                'data' => $result->toArray(),
                'message' => 'Estructura REM parseada exitosamente',
                'errors' => null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => null,
                'message' => 'Error al parsear el archivo',
                'errors' => [['type' => 'exception', 'message' => $e->getMessage()]],
            ], 500);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
