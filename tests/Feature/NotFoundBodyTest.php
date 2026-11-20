<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('answers the same neutral 404 body for a missing record, a binding miss and an abort(404)', function () {
    Route::middleware('api')->get('/api/_t/find/{id}', fn ($id) => Invoice::findOrFail($id));
    Route::middleware('api')->get('/api/_t/bind/{invoice}', fn (Invoice $invoice) => $invoice);
    Route::middleware('api')->get('/api/_t/foreign', fn () => abort(404));

    $bodies = [
        $this->getJson('/api/_t/find/9999'),
        $this->getJson('/api/_t/bind/9999'),
        $this->getJson('/api/_t/foreign'),
    ];

    foreach ($bodies as $r) {
        $r->assertNotFound();
        expect($r->getContent())->toBe($bodies[0]->getContent())
            ->and($r->getContent())->not->toContain('Invoice')->not->toContain('9999');
    }
    expect($bodies[0]->json('message'))->toBe(__('errors.not_found'));
});

it('translates the not found message', function () {
    Route::middleware('api')->get('/api/_t/find/{id}', fn ($id) => Invoice::findOrFail($id));

    $this->getJson('/api/_t/find/1', ['Accept-Language' => 'es'])->assertNotFound()->assertJsonPath('message', 'No encontrado.');
});

it('keeps the HTML 404 for non-JSON web requests', function () {
    Route::middleware('web')->get('/_t/find/{id}', fn ($id) => User::findOrFail($id));

    $this->get('/_t/find/9999')->assertNotFound();
});
