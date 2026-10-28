<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\BlockedJobPartController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FailedJobController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\JobOrderPartOrderController;
use App\Http\Controllers\Api\PartRequestController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RepairPhotoController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopOnboardingController;
use App\Http\Controllers\Api\SubscriptionRequestController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TechnicianPortalController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/csrf-token', function () {
        return response()->json([
            'csrf_token' => csrf_token(),
        ]);
    });

    Route::prefix('auth')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->middleware(['auth', EnsureActiveUser::class]);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
        Route::post('/password', [AuthController::class, 'changePassword'])->middleware(['auth', EnsureActiveUser::class]);
    });

    Route::prefix('onboarding')->group(function () {
        Route::get('/check-slug', [ShopOnboardingController::class, 'checkSlug']);
        Route::post('/register-shop', [ShopOnboardingController::class, 'register']);
    });

    Route::get('/track', [TrackingController::class, 'search'])->middleware('throttle:30,1');
    Route::get('/track/{jobOrderNumber}', [TrackingController::class, 'show'])->middleware('throttle:30,1');
    Route::get('/public/shops', [ShopController::class, 'publicIndex'])->middleware('throttle:30,1');
    Route::get('/shops/{shop:slug}/profile', [ShopController::class, 'publicProfile']);
    Route::get('/shops/{shop:slug}/track', [TrackingController::class, 'searchForShop'])->middleware('throttle:30,1');
    Route::get('/shops/{shop:slug}/track/{jobOrderNumber}', [TrackingController::class, 'showForShop'])->middleware('throttle:30,1');
    Route::get('/shops/{shop:slug}/track/{jobOrderNumber}/photos/{photo}', [RepairPhotoController::class, 'showForCustomer'])->name('tracking.photos.file')->middleware('throttle:30,1');
    Route::post('/shops/{shop:slug}/appointments', [AppointmentController::class, 'storeForShop']);
    Route::get('/shops/{shop:slug}/appointments/{confirmationCode}', [AppointmentController::class, 'showForShop'])
        ->name('public.appointment.lookup')
        ->middleware('throttle:30,1');
    Route::get('/shops/{shop:slug}/appointments/{confirmationCode}/photo', [AppointmentController::class, 'showPublicPhoto'])
        ->name('public.appointment.photo')
        ->middleware('throttle:30,1');

    Route::middleware(['auth', EnsureActiveUser::class])->prefix('admin')->group(function () {
        Route::get('/alerts', [DashboardController::class, 'alerts']);
        Route::get('/subscription/options', [SubscriptionRequestController::class, 'options']);
        Route::post('/subscription/requests', [SubscriptionRequestController::class, 'store']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/shop', [ShopController::class, 'show']);
        Route::put('/shop', [ShopController::class, 'update']);
        Route::post('/shop/logo', [ShopController::class, 'uploadLogo']);
        Route::delete('/shop/logo', [ShopController::class, 'deleteLogo']);
        Route::post('/shop/service-categories', [ShopController::class, 'storeServiceCategory']);
        Route::put('/shop/service-categories/{category}', [ShopController::class, 'updateServiceCategory']);
        Route::delete('/shop/service-categories/{category}', [ShopController::class, 'deactivateServiceCategory']);
        Route::post('/shop/service-categories/{category}/services', [ShopController::class, 'storeServiceItem']);
        Route::put('/shop/services/{service}', [ShopController::class, 'updateServiceItem']);
        Route::delete('/shop/services/{service}', [ShopController::class, 'deactivateServiceItem']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/part-requests', [PartRequestController::class, 'index']);
        Route::post('/part-requests', [PartRequestController::class, 'storeForShop']);
        Route::put('/part-requests/{partRequest}', [PartRequestController::class, 'update']);
        Route::patch('/part-requests/{partRequest}/status', [PartRequestController::class, 'updateStatus']);
        Route::patch('/part-requests/{partRequest}/receive', [PartRequestController::class, 'receive']);
        Route::get('/job-order-part-orders', [JobOrderPartOrderController::class, 'index']);
        Route::post('/job-orders/{jobOrder}/part-orders', [JobOrderPartOrderController::class, 'store']);
        Route::patch('/job-order-part-orders/{partOrder}/customer-decision', [JobOrderPartOrderController::class, 'decide']);
        Route::patch('/job-order-part-orders/{partOrder}/place-order', [JobOrderPartOrderController::class, 'placeOrder']);
        Route::patch('/job-order-part-orders/{partOrder}/receive', [JobOrderPartOrderController::class, 'receive']);
        Route::patch('/job-order-part-orders/{partOrder}/cancel', [JobOrderPartOrderController::class, 'cancel']);
        Route::patch('/job-orders/{jobOrder}/device-location', [JobOrderPartOrderController::class, 'updateDeviceLocation']);
        Route::get('/blocked-job-requests', [BlockedJobPartController::class, 'index']);
        Route::post('/blocked-job-orders/{jobOrder}/fulfill-parts-request', [BlockedJobPartController::class, 'fulfill']);
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::get('/appointments/{appointment}/photo', [AppointmentController::class, 'showStaffPhoto'])->name('admin.appointment.photo');
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);
        Route::post('/appointments/{appointment}/convert-to-job-order', [AppointmentController::class, 'convertToJobOrder']);

        Route::get('/job-orders/meta', [JobOrderController::class, 'meta']);
        Route::get('/job-orders', [JobOrderController::class, 'index']);
        Route::get('/job-orders/trash', [JobOrderController::class, 'trashed']);
        Route::post('/job-orders', [JobOrderController::class, 'store']);
        Route::get('/job-orders/{jobOrder}/invoice', [JobOrderController::class, 'invoice']);
        Route::get('/job-orders/{jobOrder}/print-data', [JobOrderController::class, 'printData']);
        Route::get('/job-orders/{jobOrder}', [JobOrderController::class, 'show']);
        Route::put('/job-orders/{jobOrder}', [JobOrderController::class, 'update']);
        Route::delete('/job-orders/{jobOrder}', [JobOrderController::class, 'destroy']);
        Route::post('/job-orders/{jobOrderId}/restore', [JobOrderController::class, 'restore']);
        Route::delete('/job-orders/{jobOrderId}/force-delete', [JobOrderController::class, 'forceDelete']);
        Route::post('/job-orders/{jobOrder}/update-status', [JobOrderController::class, 'updateStatus']);
        Route::post('/job-orders/{jobOrder}/mark-picked-up', [JobOrderController::class, 'markPickedUp']);
        Route::post('/job-orders/{jobOrder}/release-rto', [JobOrderController::class, 'releaseRto']);
        Route::post('/job-orders/{jobOrder}/prepare-rto-refunds', [JobOrderController::class, 'prepareRtoRefunds']);
        Route::post('/job-orders/{jobOrder}/legacy-rto-refunds', [JobOrderController::class, 'reconcileLegacyRtoRefund']);
        Route::post('/job-orders/{jobOrder}/refunds/{refund}/issue', [JobOrderController::class, 'issueRtoRefund']);
        Route::post('/job-orders/{jobOrder}/record-payment', [JobOrderController::class, 'recordPayment']);
        Route::post('/job-orders/{jobOrder}/update-costs', [JobOrderController::class, 'updateCosts']);
        Route::post('/job-orders/{jobOrder}/update-warranty', [JobOrderController::class, 'updateWarranty']);
        Route::post('/job-orders/{jobOrder}/issue-warranty-service', [JobOrderController::class, 'issueWarrantyService']);
        Route::post('/job-orders/{jobOrder}/cancel-warranty-service', [JobOrderController::class, 'cancelWarrantyService']);
        Route::post('/job-orders/{jobOrder}/warranty-assessments', [JobOrderController::class, 'recordWarrantyAssessment']);
        Route::post('/job-orders/{jobOrder}/record-repair-approval', [JobOrderController::class, 'recordRepairApproval']);
        Route::post('/job-orders/{jobOrder}/rate-technician', [JobOrderController::class, 'rateTechnician']);
        Route::post('/job-orders/{jobOrder}/photos', [JobOrderController::class, 'storePhoto']);
        Route::post('/job-orders/{jobOrder}/photos/{photo}/visibility', [JobOrderController::class, 'updatePhotoVisibility']);
        Route::delete('/job-orders/{jobOrder}/photos/{photo}', [JobOrderController::class, 'deletePhoto']);
        Route::get('/job-orders/{jobOrder}/photos/{photo}/file', [RepairPhotoController::class, 'showForStaff'])->name('admin.job-order.photos.file');
        Route::post('/job-orders/{jobOrder}/notifications/{notification}/retry', [JobOrderController::class, 'retryNotification']);
        Route::post('/job-orders/{jobOrder}/parts', [JobOrderController::class, 'addPart']);
        Route::put('/job-orders/{jobOrder}/parts/{part}', [JobOrderController::class, 'updatePart']);
        Route::delete('/job-orders/{jobOrder}/parts/{part}', [JobOrderController::class, 'deletePart']);

        Route::get('/reports', [ReportsController::class, 'index']);
        Route::get('/reports/export/csv', [ReportsController::class, 'exportCsv']);
        Route::get('/reports/export/pdf', [ReportsController::class, 'exportPdf']);

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
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/inventory', [InventoryController::class, 'store']);
        Route::get('/inventory/{sparePart}', [InventoryController::class, 'show']);
        Route::put('/inventory/{sparePart}', [InventoryController::class, 'update']);
        Route::post('/inventory/{sparePart}/adjust', [InventoryController::class, 'adjustStock']);
        Route::delete('/inventory/{sparePart}', [InventoryController::class, 'destroy']);
    });

    Route::middleware(['auth', EnsureActiveUser::class])->prefix('platform')->group(function () {
        Route::get('/dashboard', [PlatformController::class, 'dashboard']);
        Route::get('/failed-jobs', [FailedJobController::class, 'index']);
        Route::post('/failed-jobs/{id}/retry', [FailedJobController::class, 'retry']);
        Route::delete('/failed-jobs/{id}', [FailedJobController::class, 'destroy']);
        Route::get('/shops', [PlatformController::class, 'shops']);
        Route::get('/subscription-settings', [PlatformController::class, 'subscriptionSettings']);
        Route::put('/subscription-settings', [PlatformController::class, 'updateSubscriptionSettings']);
        Route::get('/subscription-requests', [PlatformController::class, 'subscriptionRequests']);
        Route::post('/subscription-requests/{subscriptionRequest}/approve', [PlatformController::class, 'approveSubscriptionRequest']);
        Route::post('/subscription-requests/{subscriptionRequest}/reject', [PlatformController::class, 'rejectSubscriptionRequest']);
        Route::put('/shops/{shop}', [PlatformController::class, 'updateShop']);
        Route::post('/shops/{shop}/toggle', [PlatformController::class, 'toggleShop']);
        Route::post('/shops/{shop}/reset-admin-password', [PlatformController::class, 'resetShopAdminPassword']);
        Route::post('/shops/{shop}/subscription-payments', [PlatformController::class, 'storeSubscriptionPayment']);
    });

    Route::middleware(['auth', EnsureActiveUser::class])->prefix('technician')->group(function () {
        Route::get('/alerts', [TechnicianPortalController::class, 'alerts']);
        Route::get('/dashboard', [TechnicianPortalController::class, 'dashboard']);
        Route::post('/part-requests', [PartRequestController::class, 'store']);
        Route::post('/job-orders/{jobOrder}/blocked-part-requests', [BlockedJobPartController::class, 'store']);
        Route::post('/job-orders/{jobOrder}/part-orders', [JobOrderPartOrderController::class, 'store']);
        Route::patch('/job-orders/{jobOrder}/part-orders/{partOrder}/issue', [JobOrderPartOrderController::class, 'issueToJob']);
        Route::patch('/job-orders/{jobOrder}/device-location', [JobOrderPartOrderController::class, 'updateDeviceLocation']);
        Route::get('/job-orders/{jobOrder}', [TechnicianPortalController::class, 'showJob']);
        Route::post('/job-orders/{jobOrder}/record-repair-approval', [TechnicianPortalController::class, 'recordRepairApproval']);
        Route::post('/job-orders/{jobOrder}/update-status', [TechnicianPortalController::class, 'updateStatus']);
        Route::post('/job-orders/{jobOrder}/photos', [TechnicianPortalController::class, 'storePhoto']);
        Route::delete('/job-orders/{jobOrder}/photos/{photo}', [TechnicianPortalController::class, 'deletePhoto']);
        Route::post('/job-orders/{jobOrder}/photos/{photo}/visibility', [TechnicianPortalController::class, 'updatePhotoVisibility']);
        Route::get('/job-orders/{jobOrder}/photos/{photo}/file', [RepairPhotoController::class, 'showForTechnician'])->name('technician.job-order.photos.file');
        Route::post('/job-orders/{jobOrder}/parts', [TechnicianPortalController::class, 'addPart']);
        Route::delete('/job-orders/{jobOrder}/parts/{part}', [TechnicianPortalController::class, 'removePart']);
    });
});
