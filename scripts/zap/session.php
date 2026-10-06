<?php

/*
| OWASP ZAP scan session (Phase 8B.7). CI and development only.
|
| Creates (or resets) a dedicated Director General test account with a
| fresh random password and TOTP secret, signs in through the real login
| and two-factor challenge over HTTP (as a browser would), and prints the
| session cookie as "name=value" for ZAP. Nothing is written to disk; the
| password exists only for the duration of this script.
|
| Refuses to run in production. Not an Artisan command, so it is never
| available inside the application.
|
|   docker compose exec -T app php scripts/zap/session.php
*/

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Refused: scan sessions are never created in production.\n");
    exit(1);
}

$email = 'zap.scanner@buffalo.test';
$password = Str::password(32);
$secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

$user = User::query()->withTrashed()->where('email', $email)->first()
    ?? User::factory()->directorGeneral()->create(['email' => $email, 'name' => 'ZAP Scanner (test account)']);
$user->forceFill([
    'password' => Hash::make($password),
    'two_factor_secret' => encrypt($secret),
    'two_factor_confirmed_at' => now(),
    'is_active' => true,
    'deleted_at' => null,
])->save();

$base = getenv('ZAP_LOGIN_URL') ?: 'http://nginx:8080';
$jar = new CookieJar;
$http = new Client(['base_uri' => $base, 'cookies' => $jar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 20]);
$xsrf = fn (): string => urldecode((string) $jar->getCookieByName('XSRF-TOKEN')?->getValue());
$post = fn (string $path, array $form) => $http->post($path, ['headers' => ['X-XSRF-TOKEN' => $xsrf(), 'Accept' => 'text/html'], 'form_params' => $form]);

$http->get('/login');
$login = $post('/login', ['email' => $email, 'password' => $password]);
$challenge = $post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
$dashboard = $http->get('/dashboard');

if ($dashboard->getStatusCode() !== 200) {
    fwrite(STDERR, sprintf("Sign-in failed (login %d, challenge %d, dashboard %d).\n", $login->getStatusCode(), $challenge->getStatusCode(), $dashboard->getStatusCode()));
    exit(1);
}

$name = (string) config('session.cookie');
echo $name.'='.$jar->getCookieByName($name)?->getValue();
