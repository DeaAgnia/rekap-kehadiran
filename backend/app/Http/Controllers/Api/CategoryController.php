<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Category::query()->withCount('employees')->orderBy('group')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        try {
            $category = Category::create($request->validated());
            ActivityLogger::success('category.created', 'Kategori berhasil ditambahkan.', $category);

            return response()->json(['message' => 'Kategori berhasil ditambahkan.', 'data' => $category], 201);
        } catch (Throwable $exception) {
            ActivityLogger::error('category.create_failed', 'Kategori gagal ditambahkan.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json(['data' => $category->loadCount('employees')]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        try {
            $category->update($request->validated());
            ActivityLogger::success('category.updated', 'Kategori berhasil diperbarui.', $category);

            return response()->json(['message' => 'Kategori berhasil diperbarui.', 'data' => $category->fresh()->loadCount('employees')]);
        } catch (Throwable $exception) {
            ActivityLogger::error('category.update_failed', 'Kategori gagal diperbarui.', $category, ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function updateStatus(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $category->update($validated);
        ActivityLogger::success('category.status_updated', 'Status kategori berhasil diperbarui.', $category, $validated);

        return response()->json(['message' => 'Status kategori berhasil diperbarui.', 'data' => $category->fresh()->loadCount('employees')]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->employees()->exists()) {
            ActivityLogger::error('category.delete_failed', 'Kategori masih digunakan oleh pegawai.', $category);

            return response()->json(['message' => 'Kategori tidak dapat dihapus karena masih digunakan oleh pegawai.'], 409);
        }

        $category->delete();
        ActivityLogger::success('category.deleted', 'Kategori berhasil dihapus.', $category);

        return response()->json(['message' => 'Kategori berhasil dihapus.']);
    }
}
