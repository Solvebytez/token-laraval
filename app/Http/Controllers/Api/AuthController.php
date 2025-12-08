<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'user',
            ]);

            // Generate access token
            $accessToken = JWTAuth::fromUser($user);
            
            // Generate refresh token with 30 days TTL and 'type' => 'refresh' claim
            // Use factory with setTTL and customClaims, then encode
            try {
                $factory = JWTAuth::factory();
                $factory->setTTL(config('jwt.refresh_ttl', 43200)); // 30 days
                
                // Build the payload
                $payload = $factory->customClaims(['type' => 'refresh'])->make($user);
                
                // Encode to get token string
                $refreshToken = JWTAuth::encode($payload)->get();
                
                // Validate
                if (!is_string($refreshToken) || strlen($refreshToken) < 50) {
                    throw new \Exception('Invalid token generated');
                }
            } catch (\Exception $e) {
                Log::error('Error generating refresh token during registration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Fallback
                $refreshToken = JWTAuth::fromUser($user);
            }

            Log::info('User registered', [
                'user_id' => $user->id, 
                'email' => $user->email,
                'refresh_token_length' => strlen($refreshToken)
            ]);

            // Ensure refresh_token is always a string in the response
            $refreshTokenValue = is_string($refreshToken) ? $refreshToken : (string) $refreshToken;
            
            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshTokenValue,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // in seconds
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Login user and generate tokens
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $credentials = $request->only('email', 'password');

        try {
            if (!$accessToken = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            $user = auth()->user();
            
            // Generate refresh token with 30 days TTL and 'type' => 'refresh' claim
            // Use factory with setTTL and customClaims, then encode
            try {
                $factory = JWTAuth::factory();
                $factory->setTTL(config('jwt.refresh_ttl', 43200)); // 30 days
                
                // Build the payload
                $payload = $factory->customClaims(['type' => 'refresh'])->make($user);
                
                // Encode to get token string - this is the key step
                $refreshToken = JWTAuth::encode($payload)->get();
                
                // Validate
                if (!is_string($refreshToken) || strlen($refreshToken) < 50) {
                    throw new \Exception('Invalid token generated');
                }
                
                Log::info('Refresh token generated', [
                    'user_id' => $user->id,
                    'length' => strlen($refreshToken),
                ]);
            } catch (\Exception $e) {
                Log::error('Refresh token generation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Fallback
                $refreshToken = JWTAuth::fromUser($user);
            }

            Log::info('User logged in', [
                'user_id' => $user->id, 
                'email' => $user->email,
                'refresh_token_length' => is_string($refreshToken) ? strlen($refreshToken) : 0
            ]);

            // Ensure refresh_token is always a string in the response
            $refreshTokenValue = is_string($refreshToken) ? $refreshToken : '';
            
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshTokenValue,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // in seconds
                ],
            ], 200);

        } catch (JWTException $e) {
            Log::error('JWT error during login', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Could not create token',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Refresh access token using refresh token
     * Validates that the token is a refresh token and not expired
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $refreshToken = $request->bearerToken() ?? $request->input('refresh_token');

            if (!$refreshToken) {
                Log::warning('Refresh token missing', ['ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Refresh token is required',
                ], 400);
            }

            // Set token and validate it
            try {
                JWTAuth::setToken($refreshToken);
                
                // Get the payload to check token type
                $payload = JWTAuth::getPayload();
                
                // Check if token has expired
                $exp = $payload->get('exp');
                $now = time();
                if ($exp && $exp < $now) {
                    Log::warning('Refresh token has expired', [
                        'exp' => $exp,
                        'now' => $now,
                        'expired_seconds_ago' => $now - $exp,
                        'ip' => $request->ip(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Refresh token has expired',
                    ], 401);
                }
                
                // Validate that this is a refresh token (has 'type' => 'refresh' claim)
                $tokenType = $payload->get('type');
                if ($tokenType !== 'refresh') {
                    Log::warning('Invalid token type used for refresh', [
                        'token_type' => $tokenType,
                        'ip' => $request->ip(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid token type. Refresh token required.',
                    ], 401);
                }
            } catch (JWTException $e) {
                Log::error('JWT error during refresh validation', [
                    'error' => $e->getMessage(),
                    'ip' => $request->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired refresh token',
                    'error' => config('app.debug') ? $e->getMessage() : 'Invalid or expired refresh token',
                ], 401);
            }

            // Authenticate and get user
            $user = JWTAuth::authenticate();

            if (!$user) {
                Log::warning('User not found for refresh token', ['ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid refresh token',
                ], 401);
            }

            // Generate new access token (15 minutes)
            $newAccessToken = JWTAuth::fromUser($user);
            
            // Generate new refresh token (30 days) - rotate refresh token for security
            // Use factory with setTTL and customClaims, then encode
            try {
                $factory = JWTAuth::factory();
                $factory->setTTL(config('jwt.refresh_ttl', 43200)); // 30 days
                
                // Build the payload
                $payload = $factory->customClaims(['type' => 'refresh'])->make($user);
                
                // Encode to get token string
                $newRefreshToken = JWTAuth::encode($payload)->get();
                
                // Validate
                if (!is_string($newRefreshToken) || strlen($newRefreshToken) < 50) {
                    throw new \Exception('Invalid token generated');
                }
            } catch (\Exception $e) {
                Log::error('Error generating new refresh token during refresh', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Fallback
                $newRefreshToken = JWTAuth::fromUser($user);
            }

            Log::info('Token refreshed successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'new_refresh_token_length' => strlen($newRefreshToken)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'access_token' => $newAccessToken,
                    'refresh_token' => is_string($newRefreshToken) ? $newRefreshToken : (string) $newRefreshToken,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60, // in seconds
                ],
            ], 200);

        } catch (JWTException $e) {
            Log::error('Token refresh error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not refresh token',
                'error' => config('app.debug') ? $e->getMessage() : 'Invalid or expired refresh token',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Unexpected error during token refresh', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                ],
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }
    }

    /**
     * Logout user (invalidate token)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            Log::info('User logged out', ['user_id' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out',
            ], 200);

        } catch (JWTException $e) {
            Log::error('Logout error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to logout',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
