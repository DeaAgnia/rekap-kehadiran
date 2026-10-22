<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->with('categories:id,name,group,active')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->string('search'));
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('nid_nip', 'like', "%{$search}%")
                    ->orWhere('machine_id', 'like', "%{$search}%"));
            })
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->when($request->integer('category_id'), fn ($query, $categoryId) => $query->whereHas('categories', fn ($categoryQuery) => $categoryQuery->whereKey($categoryId)))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $employees]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        try {
            $employee = DB::transaction(function () use ($request): Employee {
                $validated = $request->validated();
                $categoryIds = $validated['category_ids'];
                unset($validated['category_ids']);

                $employee = Employee::create($validated);
                $employee->categories()->sync($categoryIds);

                return $employee->load('categories:id,name,group,active');
            });

            ActivityLogger::success('employee.created', 'Data pegawai berhasil ditambahkan.', $employee);

            return response()->json(['message' => 'Data pegawai berhasil ditambahkan.', 'data' => $employee], 201);
        } catch (Throwable $exception) {
            ActivityLogger::error('employee.create_failed', 'Data pegawai gagal ditambahkan.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $employee->load('categories:id,name,group,active')]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $employee): void {
                $validated = $request->validated();
                $categoryIds = $validated['category_ids'];
                unset($validated['category_ids']);
                $employee->update($validated);
                $employee->categories()->sync($categoryIds);
            });

            ActivityLogger::success('employee.updated', 'Data pegawai berhasil diperbarui.', $employee);

            return response()->json(['message' => 'Data pegawai berhasil diperbarui.', 'data' => $employee->fresh()->load('categories:id,name,group,active')]);
        } catch (Throwable $exception) {
            ActivityLogger::error('employee.update_failed', 'Data pegawai gagal diperbarui.', $employee, ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function updateStatus(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $employee->update($validated);
        ActivityLogger::success('employee.status_updated', 'Status pegawai berhasil diperbarui.', $employee, $validated);

        return response()->json(['message' => 'Status pegawai berhasil diperbarui.', 'data' => $employee->fresh()->load('categories:id,name,group,active')]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        try {
            $employee->delete();
            ActivityLogger::success('employee.deleted', 'Data pegawai berhasil dihapus.', $employee);

            return response()->json(['message' => 'Data pegawai berhasil dihapus.']);
        } catch (Throwable $exception) {
            ActivityLogger::error('employee.delete_failed', 'Data pegawai gagal dihapus.', $employee, ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
