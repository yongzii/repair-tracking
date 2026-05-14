<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FailedJobController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\PartRequestController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopOnboardingController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TechnicianPortalController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/csrf-token', function () {
        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    });

    Route::prefix('auth')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
    });

    Route::prefix('onboarding')->group(function () {
        Route::get('/check-slug', [ShopOnboardingController::class, 'checkSlug']);
        Route::post('/register-shop', [ShopOnboardingController::class, 'register']);
    });

    Route::get('/track', [TrackingController::class, 'search']);
    Route::get('/track/{jobOrderNumber}', [TrackingController::class, 'show']);
    Route::get('/shops/{shop:slug}/track', [TrackingController::class, 'searchForShop']);
    Route::get('/shops/{shop:slug}/track/{jobOrderNumber}', [TrackingController::class, 'showForShop']);
    Route::post('/shops/{shop:slug}/appointments', [AppointmentController::class, 'storeForShop']);
    Route::get('/shops/{shop:slug}/appointments/{confirmationCode}', [AppointmentController::class, 'showForShop']);

    Route::middleware('auth')->prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/shop', [ShopController::class, 'show']);
        Route::put('/shop', [ShopController::class, 'update']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/part-requests', [PartRequestController::class, 'index']);
        Route::post('/part-requests/{jobOrder}/fulfill', [PartRequestController::class, 'fulfill']);
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);
        Route::post('/appointments/{appointment}/convert-to-job-order', [AppointmentController::class, 'convertToJobOrder']);

        Route::get('/job-orders/meta', [JobOrderController::class, 'meta']);
        Route::get('/job-orders', [JobOrderController::class, 'index']);
        Route::get('/job-orders/trash', [JobOrderController::class, 'trashed']);
        Route::post('/job-orders', [JobOrderController::class, 'store']);
        Route::get('/job-orders/{jobOrder}/invoice', [JobOrderController::class, 'invoice']);
        Route::get('/job-orders/{jobOrder}', [JobOrderController::class, 'show']);
        Route::put('/job-orders/{jobOrder}', [JobOrderController::class, 'update']);
        Route::delete('/job-orders/{jobOrder}', [JobOrderController::class, 'destroy']);
        Route::post('/job-orders/{jobOrderId}/restore', [JobOrderController::class, 'restore']);
        Route::delete('/job-orders/{jobOrderId}/force-delete', [JobOrderController::class, 'forceDelete']);
        Route::post('/job-orders/{jobOrder}/update-status', [JobOrderController::class, 'updateStatus']);
        Route::post('/job-orders/{jobOrder}/mark-picked-up', [JobOrderController::class, 'markPickedUp']);
        Route::post('/job-orders/{jobOrder}/record-payment', [JobOrderController::class, 'recordPayment']);
        Route::post('/job-orders/{jobOrder}/update-costs', [JobOrderController::class, 'updateCosts']);
        Route::post('/job-orders/{jobOrder}/rate-technician', [JobOrderController::class, 'rateTechnician']);
        Route::post('/job-orders/{jobOrder}/parts', [JobOrderController::class, 'addPart']);
        Route::put('/job-orders/{jobOrder}/parts/{part}', [JobOrderController::class, 'updatePart']);
        Route::delete('/job-orders/{jobOrder}/parts/{part}', [JobOrderController::class, 'deletePart']);

        Route::get('/reports', [ReportsController::class, 'index']);
        Route::get('/reports/export/csv', [ReportsController::class, 'exportCsv']);
        Route::get('/reports/export/pdf', [ReportsController::class, 'exportPdf']);

        Route::get('/failed-jobs', [FailedJobController::class, 'index']);
        Route::post('/failed-jobs/{id}/retry', [FailedJobController::class, 'retry']);
        Route::delete('/failed-jobs/{id}', [FailedJobController::class, 'destroy']);

        Route::get('/technicians', [TechnicianController::class, 'index']);
        Route::post('/technicians', [TechnicianController::class, 'store']);
        Route::get('/technicians/{technician}', [TechnicianController::class, 'show']);
        Route::put('/technicians/{technician}', [TechnicianController::class, 'update']);
        Route::delete('/technicians/{technician}', [TechnicianController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/inventory', [InventoryController::class, 'store']);
        Route::get('/inventory/{sparePart}', [InventoryController::class, 'show']);
        Route::put('/inventory/{sparePart}', [InventoryController::class, 'update']);
        Route::post('/inventory/{sparePart}/adjust', [InventoryController::class, 'adjustStock']);
        Route::delete('/inventory/{sparePart}', [InventoryController::class, 'destroy']);
    });

    Route::middleware('auth')->prefix('technician')->group(function () {
        Route::get('/dashboard', [TechnicianPortalController::class, 'dashboard']);
        Route::get('/job-orders/{jobOrder}', [TechnicianPortalController::class, 'showJob']);
        Route::post('/job-orders/{jobOrder}/update-status', [TechnicianPortalController::class, 'updateStatus']);
        Route::post('/job-orders/{jobOrder}/parts', [TechnicianPortalController::class, 'addPart']);
        Route::delete('/job-orders/{jobOrder}/parts/{part}', [TechnicianPortalController::class, 'removePart']);
        Route::post('/job-orders/{jobOrder}/request-part', [TechnicianPortalController::class, 'requestPart']);
    });
});
