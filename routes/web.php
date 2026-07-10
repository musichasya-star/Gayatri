<?php

use App\Http\Controllers\Admin\AiAutomationApprovalController;
use App\Http\Controllers\Admin\AiAutomationLogController;
use App\Http\Controllers\Admin\AiAutomationRuleController;
use App\Http\Controllers\Admin\AiAutomationTestController;
use App\Http\Controllers\Admin\AiDataAutomationController;
use App\Http\Controllers\Admin\AiExtractedDataController;
use App\Http\Controllers\Admin\AiGuardrailController;
use App\Http\Controllers\Admin\AiLogController;
use App\Http\Controllers\Admin\AiPersonaController;
use App\Http\Controllers\Admin\AiSimulatorController;
use App\Http\Controllers\Admin\AvailabilitySlotController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\FollowupController;
use App\Http\Controllers\Admin\InboxController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\LandingPageCmsController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Admin\ReminderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RetentionController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TherapistController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WhatsAppSessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\Webhooks\WahaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingPageController::class, 'show'])->name('landing.show');
Route::get('/booking', [LandingPageController::class, 'bookingPage'])->name('landing.booking-page');
Route::post('/booking-online', [LandingPageController::class, 'booking'])->name('landing.booking');
Route::post('/booking-online/reschedule', [LandingPageController::class, 'reschedule'])->name('landing.booking.reschedule');

Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
Route::post('/webhooks/waha/messages', [WahaWebhookController::class, 'messages'])->name('webhooks.waha.messages');
Route::post('/webhooks/waha/status', [WahaWebhookController::class, 'status'])->name('webhooks.waha.status');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'role:owner,manager,admin,sales,therapist'])
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
        Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
        Route::post('/inbox/{conversation}/takeover', [InboxController::class, 'takeover'])->name('inbox.takeover');
        Route::post('/inbox/{conversation}/close', [InboxController::class, 'close'])->name('inbox.close');
        Route::post('/inbox/{conversation}/followup', [InboxController::class, 'followup'])->name('inbox.followup');
        Route::post('/inbox/{conversation}/note', [InboxController::class, 'note'])->name('inbox.note');
        Route::post('/inbox/{conversation}/delete-history', [InboxController::class, 'deleteHistory'])->name('inbox.delete-history');
        Route::post('/inbox/messages/{message}/retry', [InboxController::class, 'retry'])->name('inbox.messages.retry');
        Route::middleware('role:owner,manager,admin,sales')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::post('/customers/import', [CustomerController::class, 'import'])->name('customers.import');
            Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::post('/customers/{customer}/archive', [CustomerController::class, 'archive'])->name('customers.archive');
        });

        Route::middleware('role:owner,manager,admin')->group(function () {
            Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::get('/bookings/calendar', [BookingController::class, 'calendar'])->name('bookings.calendar');
            Route::get('/bookings/create', [BookingController::class, 'create'])->name('bookings.create');
            Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
            Route::get('/bookings/{booking}/edit', [BookingController::class, 'edit'])->name('bookings.edit');
            Route::put('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
            Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');
            Route::get('/availability', [AvailabilitySlotController::class, 'index'])->name('availability.index');
            Route::post('/availability', [AvailabilitySlotController::class, 'store'])->name('availability.store');
            Route::post('/availability/bulk-generate', [AvailabilitySlotController::class, 'bulkGenerate'])->name('availability.bulk-generate');
            Route::delete('/availability/bulk-delete', [AvailabilitySlotController::class, 'bulkDestroy'])->name('availability.bulk-destroy');
            Route::put('/availability/{slot}', [AvailabilitySlotController::class, 'update'])->name('availability.update');
            Route::post('/availability/{slot}/block', [AvailabilitySlotController::class, 'block'])->name('availability.block');
            Route::delete('/availability/{slot}', [AvailabilitySlotController::class, 'destroy'])->name('availability.destroy');
            Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
            Route::post('/reminders', [ReminderController::class, 'store'])->name('reminders.store');
            Route::post('/reminders/{reminder}/retry', [ReminderController::class, 'retry'])->name('reminders.retry');
            Route::post('/reminders/{reminder}/send-now', [ReminderController::class, 'sendNow'])->name('reminders.send-now');
            Route::post('/reminders/{reminder}/cancel', [ReminderController::class, 'cancel'])->name('reminders.cancel');
        });
        Route::middleware('role:owner,manager,admin,sales')->group(function () {
            Route::get('/followups', [FollowupController::class, 'index'])->name('followups.index');
            Route::get('/followups/create', [FollowupController::class, 'create'])->name('followups.create');
            Route::post('/followups', [FollowupController::class, 'store'])->name('followups.store');
            Route::get('/followups/{followup}', [FollowupController::class, 'show'])->name('followups.show');
            Route::get('/followups/{followup}/edit', [FollowupController::class, 'edit'])->name('followups.edit');
            Route::put('/followups/{followup}', [FollowupController::class, 'update'])->name('followups.update');
            Route::post('/followups/{followup}/send', [FollowupController::class, 'send'])->name('followups.send');
            Route::post('/followups/{followup}/complete', [FollowupController::class, 'complete'])->name('followups.complete');
            Route::get('/retention', [RetentionController::class, 'index'])->name('retention');
            Route::post('/retention/generate-followups', [RetentionController::class, 'generate'])->name('retention.generate-followups');
            Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
            Route::post('/feedback/{feedback}/respond', [FeedbackController::class, 'respond'])->name('feedback.respond');
        });
        Route::middleware('role:owner,manager,admin,sales')->group(function () {
            Route::get('/promos', [PromoController::class, 'index'])->name('promos.index');
            Route::get('/promos/create', [PromoController::class, 'create'])->name('promos.create');
            Route::post('/promos', [PromoController::class, 'store'])->name('promos.store');
            Route::get('/promos/{promo}/edit', [PromoController::class, 'edit'])->name('promos.edit');
            Route::put('/promos/{promo}', [PromoController::class, 'update'])->name('promos.update');

            Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
            Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
            Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
            Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
            Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('/campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])->name('campaigns.schedule');
            Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
        });
        Route::middleware('role:owner,manager')->group(function () {
            Route::post('/campaigns/{campaign}/approve', [CampaignController::class, 'approve'])->name('campaigns.approve');
        });

        Route::middleware('role:owner,manager,admin')->group(function () {
            Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
            Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
            Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
            Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
            Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::post('/branches/{branch}/archive', [BranchController::class, 'archive'])->name('branches.archive');

            Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
            Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
            Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
            Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::post('/services/{service}/archive', [ServiceController::class, 'archive'])->name('services.archive');

            Route::get('/landing-page', [LandingPageCmsController::class, 'index'])->name('landing.index');
            Route::post('/landing-page/settings', [LandingPageCmsController::class, 'updateSettings'])->name('landing.settings.update');
            Route::post('/landing-page/sections', [LandingPageCmsController::class, 'storeSection'])->name('landing.sections.store');
            Route::put('/landing-page/sections/{section}', [LandingPageCmsController::class, 'updateSection'])->name('landing.sections.update');
            Route::delete('/landing-page/sections/{section}', [LandingPageCmsController::class, 'destroySection'])->name('landing.sections.destroy');
            Route::post('/landing-page/media', [LandingPageCmsController::class, 'storeMedia'])->name('landing.media.store');
            Route::delete('/landing-page/media/{media}', [LandingPageCmsController::class, 'destroyMedia'])->name('landing.media.destroy');
            Route::get('/landing-page/preview', [LandingPageCmsController::class, 'preview'])->name('landing.preview');
            Route::post('/landing-page/publish', [LandingPageCmsController::class, 'publish'])->name('landing.publish');

            Route::get('/therapists', [TherapistController::class, 'index'])->name('therapists.index');
            Route::get('/therapists/create', [TherapistController::class, 'create'])->name('therapists.create');
            Route::post('/therapists', [TherapistController::class, 'store'])->name('therapists.store');
            Route::get('/therapists/{therapist}/edit', [TherapistController::class, 'edit'])->name('therapists.edit');
            Route::put('/therapists/{therapist}', [TherapistController::class, 'update'])->name('therapists.update');
            Route::post('/therapists/{therapist}/archive', [TherapistController::class, 'archive'])->name('therapists.archive');
        });

        Route::middleware('role:owner,manager,admin')->group(function () {
            Route::get('/ai/personas', [AiPersonaController::class, 'index'])->name('ai.personas.index');
            Route::get('/ai/personas/create', [AiPersonaController::class, 'create'])->name('ai.personas.create');
            Route::post('/ai/personas', [AiPersonaController::class, 'store'])->name('ai.personas.store');
            Route::get('/ai/personas/{persona}/edit', [AiPersonaController::class, 'edit'])->name('ai.personas.edit');
            Route::put('/ai/personas/{persona}', [AiPersonaController::class, 'update'])->name('ai.personas.update');
            Route::post('/ai/personas/{persona}/activate', [AiPersonaController::class, 'activate'])->name('ai.personas.activate');

            Route::get('/ai/knowledge-base', [KnowledgeBaseController::class, 'index'])->name('ai.knowledge.index');
            Route::get('/ai/knowledge-base/create', [KnowledgeBaseController::class, 'create'])->name('ai.knowledge.create');
            Route::post('/ai/knowledge-base', [KnowledgeBaseController::class, 'store'])->name('ai.knowledge.store');
            Route::get('/ai/knowledge-base/{knowledge}/edit', [KnowledgeBaseController::class, 'edit'])->name('ai.knowledge.edit');
            Route::put('/ai/knowledge-base/{knowledge}', [KnowledgeBaseController::class, 'update'])->name('ai.knowledge.update');
            Route::post('/ai/knowledge-base/{knowledge}/toggle', [KnowledgeBaseController::class, 'toggle'])->name('ai.knowledge.toggle');

            Route::get('/ai/guardrails', [AiGuardrailController::class, 'index'])->name('ai.guardrails.index');
            Route::get('/ai/simulator', [AiSimulatorController::class, 'index'])->name('ai.simulator');
            Route::post('/ai/simulator', [AiSimulatorController::class, 'test'])->name('ai.simulator.test');
            Route::get('/ai/logs', [AiLogController::class, 'index'])->name('ai.logs.index');
            Route::post('/ai/logs/clear', [AiLogController::class, 'clear'])->name('ai.logs.clear');

            Route::get('/ai/data-automation', [AiDataAutomationController::class, 'index'])->name('ai.data-automation.index');
            Route::get('/ai/data-automation/rules', [AiAutomationRuleController::class, 'index'])->name('ai.data-automation.rules.index');
            Route::get('/ai/data-automation/rules/create', [AiAutomationRuleController::class, 'create'])->name('ai.data-automation.rules.create');
            Route::post('/ai/data-automation/rules', [AiAutomationRuleController::class, 'store'])->name('ai.data-automation.rules.store');
            Route::get('/ai/data-automation/rules/{rule}/edit', [AiAutomationRuleController::class, 'edit'])->name('ai.data-automation.rules.edit');
            Route::put('/ai/data-automation/rules/{rule}', [AiAutomationRuleController::class, 'update'])->name('ai.data-automation.rules.update');
            Route::post('/ai/data-automation/rules/{rule}/toggle', [AiAutomationRuleController::class, 'toggle'])->name('ai.data-automation.rules.toggle');
            Route::get('/ai/data-automation/extracted-data', [AiExtractedDataController::class, 'index'])->name('ai.data-automation.extracted.index');
            Route::get('/ai/data-automation/extracted-data/{extractedData}', [AiExtractedDataController::class, 'show'])->name('ai.data-automation.extracted.show');
            Route::get('/ai/data-automation/approvals', [AiAutomationApprovalController::class, 'index'])->name('ai.data-automation.approvals.index');
            Route::get('/ai/data-automation/approvals/{approval}', [AiAutomationApprovalController::class, 'show'])->name('ai.data-automation.approvals.show');
            Route::post('/ai/data-automation/approvals/{approval}/approve', [AiAutomationApprovalController::class, 'approve'])->name('ai.data-automation.approvals.approve');
            Route::post('/ai/data-automation/approvals/{approval}/edit-approve', [AiAutomationApprovalController::class, 'editApprove'])->name('ai.data-automation.approvals.edit-approve');
            Route::post('/ai/data-automation/approvals/{approval}/reject', [AiAutomationApprovalController::class, 'reject'])->name('ai.data-automation.approvals.reject');
            Route::get('/ai/data-automation/logs', [AiAutomationLogController::class, 'index'])->name('ai.data-automation.logs.index');
            Route::get('/ai/data-automation/logs/{log}', [AiAutomationLogController::class, 'show'])->name('ai.data-automation.logs.show');
            Route::get('/ai/data-automation/test', [AiAutomationTestController::class, 'index'])->name('ai.data-automation.test');
            Route::post('/ai/data-automation/test', [AiAutomationTestController::class, 'run'])->name('ai.data-automation.test.run');
        });
        Route::middleware('role:owner,manager,admin,sales')->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{type}', [ReportController::class, 'show'])->name('reports.show');
            Route::get('/reports/{type}/export', [ReportController::class, 'export'])->name('reports.export');
        });
        Route::middleware('role:owner,manager,admin')->group(function () {
            Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
            Route::post('/settings/ai', [SettingController::class, 'updateAi'])->name('settings.ai.update');
            Route::post('/settings/ai/test', [SettingController::class, 'testAi'])->name('settings.ai.test');
            Route::post('/settings/waha', [SettingController::class, 'updateWaha'])->name('settings.waha.update');
            Route::post('/settings/waha/test', [SettingController::class, 'testWaha'])->name('settings.waha.test');

            Route::get('/whatsapp/session', [WhatsAppSessionController::class, 'index'])->name('whatsapp.session');
            Route::post('/whatsapp/session/start', [WhatsAppSessionController::class, 'start'])->name('whatsapp.session.start');
            Route::post('/whatsapp/session/stop', [WhatsAppSessionController::class, 'stop'])->name('whatsapp.session.stop');
            Route::post('/whatsapp/session/restart', [WhatsAppSessionController::class, 'restart'])->name('whatsapp.session.restart');
            Route::post('/whatsapp/session/logout', [WhatsAppSessionController::class, 'logout'])->name('whatsapp.session.logout');
        });
        Route::middleware('role:owner,manager')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
            Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        });
    });
