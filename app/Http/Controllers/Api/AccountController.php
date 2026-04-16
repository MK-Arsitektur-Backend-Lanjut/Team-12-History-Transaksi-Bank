<?php
// filepath: d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management\app\Http\Controllers\Api\AccountController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustAccountBalanceRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Requests\UpdateAccountStatusRequest;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Accounts', description: 'Account Management API')]
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $service
    ) {
    }

    #[OA\Get(
        path: '/api/accounts',
        summary: 'List accounts',
        description: 'Get paginated list of bank accounts.',
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Number of records per page.',
                schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accounts fetched successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedAccountsResponse')
            )
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        return response()->json($this->service->list($perPage));
    }

    #[OA\Post(
        path: '/api/accounts',
        summary: 'Create account',
        description: 'Create a new customer bank account.',
        tags: ['Accounts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateAccountRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Account created successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/AccountMessageResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            )
        ]
    )]
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => $account,
        ], 201);
    }

    #[OA\Get(
        path: '/api/accounts/{account}',
        summary: 'Get account detail',
        description: 'Get account detail by account id.',
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(
                name: 'account',
                in: 'path',
                required: true,
                description: 'Account id.',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account detail fetched successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/AccountDataResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Account not found.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            )
        ]
    )]
    public function show(int $account): JsonResponse
    {
        $data = $this->service->find($account);

        if (! $data) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        return response()->json(['data' => $data]);
    }

    #[OA\Patch(
        path: '/api/accounts/{account}',
        summary: 'Update account profile',
        description: 'Update account profile fields such as name, email, phone, and address.',
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(
                name: 'account',
                in: 'path',
                required: true,
                description: 'Account id.',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAccountRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account updated successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/AccountMessageResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Account not found.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            )
        ]
    )]
    public function update(UpdateAccountRequest $request, int $account): JsonResponse
    {
        $data = $this->service->find($account);

        if (! $data) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $updated = $this->service->updateProfile($data, $request->validated());

        return response()->json([
            'message' => 'Account updated successfully.',
            'data' => $updated,
        ]);
    }

    #[OA\Patch(
        path: '/api/accounts/{account}/status',
        summary: 'Update account status',
        description: 'Update account status to active, inactive, or blocked.',
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(
                name: 'account',
                in: 'path',
                required: true,
                description: 'Account id.',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAccountStatusRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account status updated successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/AccountMessageResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Account not found.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            )
        ]
    )]
    public function updateStatus(UpdateAccountStatusRequest $request, int $account): JsonResponse
    {
        $data = $this->service->find($account);

        if (! $data) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $updated = $this->service->updateStatus($data, $request->validated('status'));

        return response()->json([
            'message' => 'Account status updated successfully.',
            'data' => $updated,
        ]);
    }

    #[OA\Post(
        path: '/api/accounts/{account}/balance/adjust',
        summary: 'Adjust account balance',
        description: 'Adjust account balance atomically using credit or debit operation.',
        tags: ['Accounts'],
        parameters: [
            new OA\Parameter(
                name: 'account',
                in: 'path',
                required: true,
                description: 'Account id.',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AdjustBalanceRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Balance adjusted successfully.',
                content: new OA\JsonContent(ref: '#/components/schemas/AccountMessageResponse')
            ),
            new OA\Response(
                response: 404,
                description: 'Account not found.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Validation or business rule error.',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            )
        ]
    )]
    public function adjustBalance(AdjustAccountBalanceRequest $request, int $account): JsonResponse
    {
        $data = $this->service->find($account);

        if (! $data) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $updated = $this->service->adjustBalance(
            $data,
            $request->validated('type'),
            (float) $request->validated('amount')
        );

        return response()->json([
            'message' => 'Balance adjusted successfully.',
            'data' => $updated,
        ]);
    }
}