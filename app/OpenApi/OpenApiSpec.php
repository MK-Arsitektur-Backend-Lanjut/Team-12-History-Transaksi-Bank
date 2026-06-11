<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Team 12 - Account Management API',
    description: 'Comprehensive API for account management, transaction logging, and statement generation. Supports account CRUD operations, atomic balance updates, transaction history tracking, and statement export functionality.'
)]
#[OA\Server(
    url: 'http://127.0.0.1:8000',
    description: 'Local development server'
)]
#[OA\Schema(
    schema: 'Account',
    type: 'object',
    required: ['id', 'account_number', 'customer_name', 'email', 'status', 'balance'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'account_number', type: 'string', example: 'ACC202604151234560001'),
        new OA\Property(property: 'customer_name', type: 'string', example: 'Budi Santoso'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '08123456789'),
        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Bandung'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blocked'], example: 'active'),
        new OA\Property(property: 'balance', type: 'number', format: 'float', example: 150000.00),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-04-15T10:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-04-15T10:00:00Z')
    ]
)]
#[OA\Schema(
    schema: 'CreateAccountRequest',
    type: 'object',
    required: ['customer_name', 'email'],
    properties: [
        new OA\Property(property: 'customer_name', type: 'string', maxLength: 100, example: 'Budi Santoso'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 150, example: 'budi@example.com'),
        new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '08123456789'),
        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Bandung'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blocked'], example: 'active'),
        new OA\Property(property: 'balance', type: 'number', format: 'float', minimum: 0, example: 100000)
    ]
)]
#[OA\Schema(
    schema: 'UpdateAccountRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'customer_name', type: 'string', maxLength: 100, example: 'Budi Santoso Updated'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 150, example: 'budi.new@example.com'),
        new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '0822222222'),
        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Jakarta')
    ]
)]
#[OA\Schema(
    schema: 'UpdateAccountStatusRequest',
    type: 'object',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blocked'], example: 'blocked')
    ]
)]
#[OA\Schema(
    schema: 'AdjustBalanceRequest',
    type: 'object',
    required: ['type', 'amount'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['debit', 'credit'], example: 'credit'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01, example: 50000)
    ]
)]
#[OA\Schema(
    schema: 'CreateTransactionRequest',
    type: 'object',
    required: ['account_id', 'type', 'amount'],
    description: 'Create transaction request. Use reference_number for idempotency (same reference_number = idempotent operation).',
    properties: [
        new OA\Property(property: 'account_id', type: 'integer', description: 'Account ID (must exist)', example: 1),
        new OA\Property(property: 'type', type: 'string', enum: ['debit', 'credit'], description: 'Transaction type', example: 'credit'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01, description: 'Transaction amount', example: 100000.00),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 255, description: 'Optional transaction description', example: 'Payment for invoice'),
        new OA\Property(property: 'reference_number', type: 'string', nullable: true, maxLength: 191, description: 'Idempotency key / external reference (UUID recommended)', example: 'PAY-2026-06-03-001'),
    ]
)]
#[OA\Schema(
    schema: 'TransactionResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'transaction', ref: '#/components/schemas/Transaction')
    ]
)]
#[OA\Schema(
    schema: 'ErrorMessageResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Account not found.')
    ]
)]
#[OA\Schema(
    schema: 'AccountDataResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/Account')
    ]
)]
#[OA\Schema(
    schema: 'AccountMessageResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Account updated successfully.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/Account')
    ]
)]
#[OA\Schema(
    schema: 'PaginatedAccountsResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 120),
        new OA\Property(property: 'last_page', type: 'integer', example: 8),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Account'))
    ]
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string')
            )
        )
    ]
)]
#[OA\Schema(
    schema: 'Transaction',
    type: 'object',
    required: ['id', 'account_id', 'reference_number', 'type', 'amount', 'balance_before', 'balance_after', 'transaction_date'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'account_id', type: 'integer', example: 1),
        new OA\Property(property: 'reference_number', type: 'string', format: 'uuid', example: '550E8400-E29B-41D4-A716-446655440000'),
        new OA\Property(property: 'type', type: 'string', enum: ['debit', 'credit'], example: 'credit'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', example: 100000.00),
        new OA\Property(property: 'balance_before', type: 'number', format: 'float', example: 0.00),
        new OA\Property(property: 'balance_after', type: 'number', format: 'float', example: 100000.00),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', example: '2026-04-15T10:00:00Z'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Transaction description'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-04-15T10:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-04-15T10:00:00Z')
    ]
)]
class OpenApiSpec
{
}
