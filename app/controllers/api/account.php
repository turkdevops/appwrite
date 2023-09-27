<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Phone as EventPhone;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Utopia\Validator\Host;
use Utopia\Validator\URL;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Template\Template;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit as EventAudit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Query;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PersonalData;

$oauthDefaultSuccess = '/auth/oauth2/success';
$oauthDefaultFailure = '/auth/oauth2/failure';

App::post('/v1/account')
    ->desc('Create account')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('abuse-limit', 10)
    ->param('userId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Event $events) {
        $email = \strtolower($email);
        if ('console' === $project->getId()) {
            $whitelistEmails = $project->getAttribute('authWhitelistEmails');
            $whitelistIPs = $project->getAttribute('authWhitelistIPs');

            if (!empty($whitelistEmails) && !\in_array($email, $whitelistEmails) && !\in_array(strtoupper($email), $whitelistEmails)) {
                throw new Exception(Exception::USER_EMAIL_NOT_WHITELISTED);
            }

            if (!empty($whitelistIPs) && !\in_array($request->getIP(), $whitelistIPs)) {
                throw new Exception(Exception::USER_IP_NOT_WHITELISTED);
            }
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
        ]);
        if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($userId, $email, $name, null);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $password = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        try {
            $userId = $userId == 'unique()' ? ID::unique() : $userId;
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => $password,
                'passwordHistory' => $passwordHistory > 0 ? [$password] : [],
                'passwordUpdate' => DateTime::now(),
                'hash' => Auth::DEFAULT_ALGO,
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email, $name]),
                'accessedAt' => DateTime::now(),
            ]);
            $user->removeAttribute('$internalId');
            Authorization::skip(fn() => $dbForProject->createDocument('users', $user));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }

        Authorization::unsetRole(Role::guests()->toString());
        Authorization::setRole(Role::user($user->getId())->toString());
        Authorization::setRole(Role::users()->toString());

        $events->setParam('userId', $user->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::post('/v1/account/sessions/email')
    ->alias('/v1/account/sessions')
    ->desc('Create email session')
    ->groups(['api', 'account', 'auth', 'session'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.create')
    ->label('usage.params', ['provider:email'])
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createEmailSession')
    ->label('sdk.description', '/docs/references/account/create-session-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('events')
    ->action(function (string $email, string $password, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $events) {

        $email = \strtolower($email);
        $protocol = $request->getProtocol();

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        if (!$profile || empty($profile->getAttribute('passwordUpdate')) || !Auth::passwordVerify($password, $profile->getAttribute('password'), $profile->getAttribute('hash'), $profile->getAttribute('hashOptions'))) {
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED); // User is in status blocked
        }

        $user->setAttributes($profile->getArrayCopy());

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => $email,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole(Role::user($user->getId())->toString());

        // Re-hash if not using recommended algo
        if ($user->getAttribute('hash') !== Auth::DEFAULT_ALGO) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS);
            $dbForProject->updateDocument('users', $user->getId(), $user);
        }

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));


        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('expire', $expire)
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::get('/v1/account/sessions/oauth2/:provider')
    ->desc('Create OAuth2 session')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Session')
    ->label('sdk.description', '/docs/references/account/create-session-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('providers'), fn($node) => (!$node['mock'])))) . '.')
    ->param('success', '', fn($clients) => new Host($clients), 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', '', fn($clients) => new Host($clients), 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function (string $provider, string $success, string $failure, array $scopes, Request $request, Response $response, Document $project) use ($oauthDefaultSuccess, $oauthDefaultFailure) {

        $protocol = $request->getProtocol();

        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $providerEnabled = $project->getAttribute('authProviders', [])[$provider . 'Enabled'] ?? false;

        if (!$providerEnabled) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.');
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        if (empty($success)) {
            $success = $protocol . '://' . $request->getHostname() . $oauthDefaultSuccess;
        }

        if (empty($failure)) {
            $failure = $protocol . '://' . $request->getHostname() . $oauthDefaultFailure;
        }

        $oauth2 = new $className($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

App::get('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 callback')
    ->groups(['account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query([
                    'project' => $projectId,
                    'code' => $code,
                    'state' => $state,
                    'error' => $error,
                    'error_description' => $error_description
                ]));
    });

App::post('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 callback')
    ->groups(['account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query([
                    'project' => $projectId,
                    'code' => $code,
                    'state' => $state,
                    'error' => $error,
                    'error_description' => $error_description
                ]));
    });

App::get('/v1/account/sessions/oauth2/:provider/redirect')
    ->desc('OAuth2 redirect')
    ->groups(['api', 'account', 'session'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('audits.userId', '{user.$id}')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->label('usage.metric', 'sessions.{scope}.requests.create')
    ->label('usage.params', ['provider:{request.provider}'])
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'OAuth2 state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('events')
    ->action(function (string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Reader $geodb, Event $events) use ($oauthDefaultSuccess) {

        $protocol = $request->getProtocol();
        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $validateURL = new URL();
        $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';
        $providerEnabled = $project->getAttribute('authProviders', [])[$provider . 'Enabled'] ?? false;

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $providers = Config::getParam('providers');
        $providerName = $providers[$provider]['name'] ?? '';

        /** @var Appwrite\Auth\OAuth2 $oauth2 */
        $oauth2 = new $className($appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Exception $exception) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to parse login state params as passed from OAuth2 provider');
            }
        } else {
            $state = $defaultState;
        }

        if (!$validateURL->isValid($state['success'])) {
            throw new Exception(Exception::PROJECT_INVALID_SUCCESS_URL);
        }

        if (!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
            throw new Exception(Exception::PROJECT_INVALID_FAILURE_URL);
        }
        $failure = [];
        if (!empty($state['failure'])) {
            $failure = URLParser::parse($state['failure']);
        }
        $failureRedirect = (function (string $type, ?string $message = null, ?int $code = null) use ($failure, $response) {
            $exception = new Exception($type, $message, $code);
            if (!empty($failure)) {
                $query = URLParser::parseQuery($failure['query']);
                $query['error'] = json_encode([
                    'message' => $exception->getMessage(),
                    'type' => $exception->getType(),
                    'code' => !\is_null($code) ? $code : $exception->getCode(),
                ]);
                $failure['query'] = URLParser::unparseQuery($query);
                $response->redirect(URLParser::unparse($failure), 301);
            }

            throw $exception;
        });

        if (!$providerEnabled) {
            $failureRedirect(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        if (!empty($error)) {
            $message = 'The ' . $providerName . ' OAuth2 provider returned an error: ' . $error;
            if (!empty($error_description)) {
                $message .= ': ' . $error_description;
            }
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, $message);
        }

        if (empty($code)) {
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Missing OAuth2 code. Please contact the Appwrite team for additional support.');
        }

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $accessToken = '';
        $refreshToken = '';
        $accessTokenExpiry = 0;

        try {
            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code);
        } catch (OAuth2Exception $ex) {
            $failureRedirect(
                $ex->getType(),
                'Failed to obtain access token. The ' . $providerName . ' OAuth2 provider returned an error: ' . $ex->getMessage(),
                $ex->getCode(),
            );
        }

        $oauth2ID = $oauth2->getUserID($accessToken);
        if (empty($oauth2ID)) {
            $failureRedirect(Exception::USER_MISSING_ID);
        }

        $name = $oauth2->getUserName($accessToken);
        $email = $oauth2->getUserEmail($accessToken);

        // Check if this identity is connected to a different user
        if (!$user->isEmpty()) {
            $userId = $user->getId();

            $identitiesWithMatchingEmail = $dbForProject->find('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userId', $userId),
            ]);
            if (!empty($identitiesWithMatchingEmail)) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        }

        $sessions = $user->getAttribute('sessions', []);
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $current = Auth::sessionVerify($sessions, Auth::$secret, $authDuration);

        if ($current) { // Delete current session of new one.
            $currentDocument = $dbForProject->getDocument('sessions', $current);
            if (!$currentDocument->isEmpty()) {
                $dbForProject->deleteDocument('sessions', $currentDocument->getId());
                $dbForProject->deleteCachedDocument('users', $user->getId());
            }
        }

        if ($user->isEmpty()) {
            $session = $dbForProject->findOne('sessions', [ // Get user by provider id
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$oauth2ID]),
            ]);
            if ($session !== false && !$session->isEmpty()) {
                $user->setAttributes($dbForProject->getDocument('users', $session->getAttribute('userId'))->getArrayCopy());
            }
        }

        if ($user === false || $user->isEmpty()) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            if (empty($email)) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'OAuth provider failed to return email.');
            }

            /**
             * Is verified is not used yet, since we don't know after an accout is created anymore if it was verified or not.
             */
            $isVerified = $oauth2->isEmailVerified($accessToken);

            $userWithEmail = $dbForProject->findOne('users', [
                Query::equal('email', [$email]),
            ]);
            if ($userWithEmail !== false && !$userWithEmail->isEmpty()) {
                $user->setAttributes($userWithEmail->getArrayCopy());
            }

            // If user is not found, check if there is an identity with the same provider user ID
            if ($user === false || $user->isEmpty()) {
                $identity = $dbForProject->findOne('identities', [
                    Query::equal('provider', [$provider]),
                    Query::equal('providerUid', [$oauth2ID]),
                ]);

                if ($identity !== false && !$identity->isEmpty()) {
                    $user = $dbForProject->getDocument('users', $identity->getAttribute('userId'));
                }
            }

            if ($user === false || $user->isEmpty()) { // Last option -> create the user
                $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

                if ($limit !== 0) {
                    $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                    if ($total >= $limit) {
                        $failureRedirect(Exception::USER_COUNT_EXCEEDED);
                    }
                }

                // Makes sure this email is not already used in another identity
                $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                    Query::equal('providerEmail', [$email]),
                ]);
                if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
                    throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
                }

                try {
                    $userId = ID::unique();
                    $user->setAttributes([
                        '$id' => $userId,
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::user($userId)),
                            Permission::delete(Role::user($userId)),
                        ],
                        'email' => $email,
                        'emailVerification' => true,
                        'status' => true, // Email should already be authenticated by OAuth2 provider
                        'password' => null,
                        'hash' => Auth::DEFAULT_ALGO,
                        'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                        'passwordUpdate' => null,
                        'registration' => DateTime::now(),
                        'reset' => false,
                        'name' => $name,
                        'prefs' => new \stdClass(),
                        'sessions' => null,
                        'tokens' => null,
                        'memberships' => null,
                        'search' => implode(' ', [$userId, $email, $name]),
                        'accessedAt' => DateTime::now(),
                    ]);
                    $user->removeAttribute('$internalId');
                    Authorization::skip(fn() => $dbForProject->createDocument('users', $user));
                } catch (Duplicate) {
                    $failureRedirect(Exception::USER_ALREADY_EXISTS);
                }
            }
        }

        Authorization::setRole(Role::user($user->getId())->toString());
        Authorization::setRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            $failureRedirect(Exception::USER_BLOCKED); // User is in status blocked
        }

        $identity = $dbForProject->findOne('identities', [
            Query::equal('userInternalId', [$user->getInternalId()]),
            Query::equal('provider', [$provider]),
            Query::equal('providerUid', [$oauth2ID]),
        ]);
        if ($identity === false || $identity->isEmpty()) {
            // Before creating the identity, check if the email is already associated with another user
            $userId = $user->getId();

            $identitiesWithMatchingEmail = $dbForProject->find('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userId', $user->getId()),
            ]);
            if (!empty($identitiesWithMatchingEmail)) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            $dbForProject->createDocument('identities', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'userInternalId' => $user->getInternalId(),
                'userId' => $userId,
                'provider' => $provider,
                'providerUid' => $oauth2ID,
                'providerEmail' => $email,
                'providerAccessToken' => $accessToken,
                'providerRefreshToken' => $refreshToken,
                'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
            ]));
        } else {
            $identity
                ->setAttribute('providerAccessToken', $accessToken)
                ->setAttribute('providerRefreshToken', $refreshToken)
                ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry));
            $dbForProject->updateDocument('identities', $identity->getId(), $identity);
        }

        // Create session token, verify user account and update OAuth2 ID and Access Token
        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'provider' => $provider,
            'providerUid' => $oauth2ID,
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $refreshToken,
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        if (empty($user->getAttribute('email'))) {
            $user->setAttribute('email', $oauth2->getUserEmail($accessToken));
        }

        if (empty($user->getAttribute('name'))) {
            $user->setAttribute('name', $oauth2->getUserName($accessToken));
        }

        $user
            ->setAttribute('status', true)
        ;

        Authorization::setRole(Role::user($user->getId())->toString());

        $dbForProject->updateDocument('users', $user->getId(), $user);

        $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $session->setAttribute('expire', $expire);

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION))
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        // Add keys for non-web platforms - TODO - add verification phase to aviod session sniffing
        if (parse_url($state['success'], PHP_URL_PATH) === $oauthDefaultSuccess) {
            $state['success'] = URLParser::parse($state['success']);
            $query = URLParser::parseQuery($state['success']['query']);
            $query['project'] = $project->getId();
            $query['domain'] = Config::getParam('cookieDomain');
            $query['key'] = Auth::$cookieName;
            $query['secret'] = Auth::encodeSession($user->getId(), $secret);
            $state['success']['query'] = URLParser::unparseQuery($query);
            $state['success'] = URLParser::unparse($state['success']);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->redirect($state['success'])
        ;
    });

App::get('/v1/account/identities')
    ->desc('List Identities')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listIdentities')
    ->label('sdk.description', '/docs/references/account/list-identities.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IDENTITY_LIST)
    ->label('sdk.offline.model', '/account/identities')
    ->param('queries', [], new Identities(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Identities::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->action(function (array $queries, Response $response, Document $user, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        $queries[] = Query::equal('userInternalId', [$user->getInternalId()]);

        // Get cursor document if there was a cursor query
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $identityId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('identities', $identityId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Identity '{$identityId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForProject->find('identities', $queries);
        $total = $dbForProject->count('identities', $filterQueries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'identities' => $results,
            'total' => $total,
        ]), Response::MODEL_IDENTITY_LIST);
    });

App::delete('/v1/account/identities/:identityId')
    ->desc('Delete Identity')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].identities.[identityId].delete')
    ->label('audits.event', 'identity.delete')
    ->label('audits.resource', 'identity/{request.$identityId}')
    ->label('audits.userId', '{user.$id}')
    ->label('usage.metric', 'identities.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteIdentity')
    ->label('sdk.description', '/docs/references/account/delete-identity.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('identityId', '', new UID(), 'Identity ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $identityId, Response $response, Database $dbForProject) {

        $identity = $dbForProject->getDocument('identities', $identityId);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('identities', $identityId);

        return $response->noContent();
    });

App::post('/v1/account/sessions/magic-url')
    ->desc('Create magic URL session')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('auth.type', 'magic-url')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMagicURLSession')
    ->label('sdk.description', '/docs/references/account/create-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('userId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn($clients) => new Host($clients), 'URL to redirect the user back to your app from the magic URL login. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('events')
    ->inject('mails')
    ->action(function (string $userId, string $email, string $url, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Locale $locale, Event $events, Mail $mails) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $result = $dbForProject->findOne('users', [Query::equal('email', [$email])]);
        if ($result !== false && !$result->isEmpty()) {
            $user->setAttributes($result->getArrayCopy());
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            $userId = $userId === 'unique()' ? ID::unique() : $userId;

            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'hash' => Auth::DEFAULT_ALGO,
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email]),
                'accessedAt' => DateTime::now(),
            ]);

            $user->removeAttribute('$internalId');
            Authorization::skip(fn () => $dbForProject->createDocument('users', $user));
        }

        $loginSecret = Auth::tokenGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_MAGIC_URL,
            'secret' => Auth::hash($loginSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole(Role::user($user->getId())->toString());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        if (empty($url)) {
            $url = $request->getProtocol() . '://' . $request->getHostname() . '/auth/magic-url';
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $loginSecret, 'expire' => $expire, 'project' => $project->getId()]);
        $url = Template::unParseURL($url);

        $body = $locale->getText("emails.magicSession.body");
        $subject = $locale->getText("emails.magicSession.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.magicSession-' . $locale->default] ?? [];

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message->setParam('{{body}}', $body);
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $mails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $mails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'subject' => $subject,
            'hello' => $locale->getText("emails.magicSession.hello"),
            'body' => $body,
            'footer' => $locale->getText("emails.magicSession.footer"),
            'thanks' => $locale->getText("emails.magicSession.thanks"),
            'signature' => $locale->getText("emails.magicSession.signature"),
            'direction' => $locale->getText('settings.direction'),
            'bg-body' => '#f7f7f7',
            'bg-content' => '#ffffff',
            'text-content' => '#000000',
            /* {{user}} ,{{team}}, {{project}} and {{redirect}} are required in the templates */
            'user' => '',
            'team' => '',
            'project' => $project->getAttribute('name'),
            'redirect' => $url
        ];

        $mails
            ->setSubject($subject)
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setRecipient($email)
            ->trigger();

        $events->setPayload(
            $response->output(
                $token->setAttribute('secret', $loginSecret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $loginSecret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/sessions/magic-url')
    ->desc('Create magic URL session (confirmation)')
    ->groups(['api', 'account', 'session'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('audits.event', 'session.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.create')
    ->label('usage.params', ['provider:magic-url'])
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMagicURLSession')
    ->label('sdk.description', '/docs/references/account/update-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('events')
    ->action(function (string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $events) {

        /** @var Utopia\Database\Document $user */

        $userFromRequest = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($userFromRequest->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $token = Auth::tokenVerify($userFromRequest->getAttribute('tokens', []), Auth::TOKEN_TYPE_MAGIC_URL, $secret);

        if (!$token) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $user->setAttributes($userFromRequest->getArrayCopy());

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_MAGIC_URL,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole(Role::user($user->getId())->toString());

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $tokens = $user->getAttribute('tokens', []);

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $token);
        $dbForProject->deleteCachedDocument('users', $user->getId());

        $user->setAttribute('emailVerification', true);

        try {
            $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (\Throwable $th) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed saving user to DB');
        }

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId());

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED);

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('expire', $expire);

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/sessions/phone')
    ->desc('Create phone session')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('auth.type', 'phone')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneSession')
    ->label('sdk.description', '/docs/references/account/create-phone-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},phone:{param-phone}')
    ->param('userId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('messaging')
    ->inject('locale')
    ->action(function (string $userId, string $phone, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Event $events, EventPhone $messaging, Locale $locale) {

        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $result = $dbForProject->findOne('users', [Query::equal('phone', [$phone])]);
        if ($result !== false && !$result->isEmpty()) {
            $user->setAttributes($result->getArrayCopy());
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? ID::unique() : $userId;
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => null,
                'phone' => $phone,
                'emailVerification' => false,
                'phoneVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $phone]),
                'accessedAt' => DateTime::now(),
            ]);

            $user->removeAttribute('$internalId');
            Authorization::skip(fn () => $dbForProject->createDocument('users', $user));
        }

        $secret = Auth::codeGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_PHONE));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => Auth::hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole(Role::user($user->getId())->toString());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

        $customTemplate = $project->getAttribute('templates', [])['sms.login-' . $locale->default] ?? [];
        if (!empty($customTemplate)) {
            $message = $customTemplate['message'] ?? $message;
        }

        $message = $message->setParam('{{token}}', $secret);
        $message = $message->render();

        $messaging
            ->setRecipient($phone)
            ->setMessage($message)
            ->trigger();

        $events->setPayload(
            $response->output(
                $token->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/sessions/phone')
    ->desc('Create phone session (confirmation)')
    ->groups(['api', 'account', 'session'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhoneSession')
    ->label('sdk.description', '/docs/references/account/update-phone-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('events')
    ->action(function (string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $events) {

        $userFromRequest = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($userFromRequest->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $token = Auth::phoneTokenVerify($userFromRequest->getAttribute('tokens', []), $secret);

        if (!$token) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $user->setAttributes($userFromRequest->getArrayCopy());

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_PHONE,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole(Role::user($user->getId())->toString());

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $token);
        $dbForProject->deleteCachedDocument('users', $user->getId());

        $user->setAttribute('phoneVerification', true);

        $dbForProject->updateDocument('users', $user->getId(), $user);

        if (false === $user) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed saving user to DB');
        }

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('expire', $expire)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/sessions/anonymous')
    ->desc('Create anonymous session')
    ->groups(['api', 'account', 'auth', 'session'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'anonymous')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.create')
    ->label('usage.params', ['provider:anonymous'])
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAnonymousSession')
    ->label('sdk.description', '/docs/references/account/create-session-anonymous.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('events')
    ->action(function (Request $request, Response $response, Locale $locale, Document $user, Document $project, Database $dbForProject, Reader $geodb, Event $events) {

        $protocol = $request->getProtocol();

        if ('console' === $project->getId()) {
            throw new Exception(Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED, 'Failed to create anonymous user');
        }

        if (!$user->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_ALREADY_EXISTS, 'Cannot create an anonymous user when logged in');
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        $userId = ID::unique();
        $user->setAttributes([
            '$id' => $userId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
            'email' => null,
            'emailVerification' => false,
            'status' => true,
            'password' => null,
            'hash' => Auth::DEFAULT_ALGO,
            'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
            'passwordUpdate' => null,
            'registration' => DateTime::now(),
            'reset' => false,
            'name' => null,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'search' => $userId,
            'accessedAt' => DateTime::now(),
        ]);
        $user->removeAttribute('$internalId');
        Authorization::skip(fn() => $dbForProject->createDocument('users', $user));

        // Create session token
        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_ANONYMOUS,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        Authorization::setRole(Role::user($user->getId())->toString());

        $session = $dbForProject->createDocument('sessions', $session-> setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('expire', $expire)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/jwt')
    ->desc('Create JWT')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'jwt')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createJWT')
    ->label('sdk.description', '/docs/references/account/create-jwt.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_JWT)
    ->label('abuse-limit', 100)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->action(function (Response $response, Document $user, Database $dbForProject) {


        $sessions = $user->getAttribute('sessions', []);
        $current = new Document();

        foreach ($sessions as $session) { /** @var Utopia\Database\Document $session */
            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $current = $session;
            }
        }

        if ($current->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
            // 'uid'    => 1,
            // 'aud'    => 'http://site.com',
            // 'scopes' => ['user'],
            // 'iss'    => 'http://api.mysite.com',
            'userId' => $user->getId(),
            'sessionId' => $current->getId(),
        ])]), Response::MODEL_JWT);
    });

App::get('/v1/account')
    ->desc('Get account')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::get('/v1/account/prefs')
    ->desc('Get account preferences')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->label('sdk.offline.model', '/account/prefs')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {

        $prefs = $user->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/account/sessions')
    ->desc('List sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listSessions')
    ->label('sdk.description', '/docs/references/account/list-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->label('sdk.offline.model', '/account/sessions')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('project')
    ->action(function (Response $response, Document $user, Locale $locale, Document $project) {

        $sessions = $user->getAttribute('sessions', []);
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $current = Auth::sessionVerify($sessions, Auth::$secret, $authDuration);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', ($current == $session->getId()) ? true : false);
            $session->setAttribute('expire', DateTime::formatTz(DateTime::addSeconds(new \DateTime($session->getCreatedAt()), $authDuration)));

            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/account/logs')
    ->desc('List logs')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/account/list-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('geodb')
    ->inject('dbForProject')
    ->action(function (array $queries, Response $response, Document $user, Locale $locale, Reader $geodb, Database $dbForProject) {

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new EventAudit($dbForProject);

        $logs = $audit->getLogsByUser($user->getInternalId(), $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);

            $output[$i] = new Document(array_merge(
                $log->getArrayCopy(),
                $log['data'],
                $detector->getOS(),
                $detector->getClient(),
                $detector->getDevice()
            ));

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByUser($user->getId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/account/sessions/:sessionId')
    ->desc('Get session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSession')
    ->label('sdk.description', '/docs/references/account/get-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('sdk.offline.model', '/account/sessions')
    ->label('sdk.offline.key', '{sessionId}')
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to get the current device session.')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('dbForProject')
    ->inject('project')
    ->action(function (?string $sessionId, Response $response, Document $user, Locale $locale, Database $dbForProject, Document $project) {

        $sessions = $user->getAttribute('sessions', []);
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret, $authDuration)
            : $sessionId;

        foreach ($sessions as $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

                $session
                    ->setAttribute('current', ($session->getAttribute('secret') == Auth::hash(Auth::$secret)))
                    ->setAttribute('countryName', $countryName)
                    ->setAttribute('expire', DateTime::formatTz(DateTime::addSeconds(new \DateTime($session->getCreatedAt()), $authDuration)))
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/name')
    ->desc('Update name')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $name, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $events) {

        $user->setAttribute('name', $name);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::patch('/v1/account/password')
    ->desc('Update password')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
    ->param('oldPassword', '', new Password(), 'Current user password. Must be at least 8 chars.', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $password, string $oldPassword, ?\DateTime $requestTimestamp, Response $response, Document $user, Document $project, Database $dbForProject, Event $events) {

        // Check old password only if its an existing user.
        if (!empty($user->getAttribute('passwordUpdate')) && !Auth::passwordVerify($oldPassword, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $user->getAttribute('passwordHistory', []);
        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $user->getAttribute('hash'), $user->getAttribute('hashOptions'));
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($user->getId(), $user->getAttribute('email'), $user->getAttribute('name'), $user->getAttribute('phone'));
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $user
            ->setAttribute('password', $newPassword)
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('passwordUpdate', DateTime::now())
            ->setAttribute('hash', Auth::DEFAULT_ALGO)
            ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::patch('/v1/account/email')
    ->desc('Update email')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $email, string $password, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $events) {
        // passwordUpdate will be empty if the user has never set a password
        $passwordUpdate = $user->getAttribute('passwordUpdate');

        if (
            !empty($passwordUpdate) &&
            !Auth::passwordVerify($password, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))
        ) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $email = \strtolower($email);

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
            Query::notEqual('userId', $user->getId()),
        ]);
        if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false) // After this user needs to confirm mail again
        ;

        if (empty($passwordUpdate)) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('passwordUpdate', DateTime::now());
        }

        try {
            $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::patch('/v1/account/phone')
    ->desc('Update phone')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/account/update-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $phone, string $password, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $events) {
        // passwordUpdate will be empty if the user has never set a password
        $passwordUpdate = $user->getAttribute('passwordUpdate');

        if (
            !empty($passwordUpdate) &&
            !Auth::passwordVerify($password, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))
        ) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $user
            ->setAttribute('phone', $phone)
            ->setAttribute('phoneVerification', false) // After this user needs to confirm phone number again
        ;

        if (empty($passwordUpdate)) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('passwordUpdate', DateTime::now());
        }

        try {
            $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::patch('/v1/account/prefs')
    ->desc('Update preferences')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account/prefs')
    ->label('sdk.offline.key', 'current')
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (array $prefs, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $events) {

        $user->setAttribute('prefs', $prefs);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::patch('/v1/account/status')
    ->desc('Update status')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/account/update-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('requestTimestamp')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (?\DateTime $requestTimestamp, Request $request, Response $response, Document $user, Database $dbForProject, Event $events) {

        $user->setAttribute('status', false);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_ACCOUNT));

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        $protocol = $request->getProtocol();
        $response
            ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

App::delete('/v1/account/sessions/:sessionId')
    ->desc('Delete session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('usage.metric', 'sessions.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to delete the current device session.')
    ->inject('requestTimestamp')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('events')
    ->inject('project')
    ->action(function (?string $sessionId, ?\DateTime $requestTimestamp, Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Event $events, Document $project) {

        $protocol = $request->getProtocol();
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret, $authDuration)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $session) {
                    return $dbForProject->deleteDocument('sessions', $session->getId());
                });

                unset($sessions[$key]);

                $session->setAttribute('current', false);

                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $session
                        ->setAttribute('current', true)
                        ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')))
                    ;

                    if (!Config::getParam('domainVerification')) {
                        $response
                            ->addHeader('X-Fallback-Cookies', \json_encode([]))
                        ;
                    }

                    $response
                        ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                        ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                    ;
                }

                $dbForProject->deleteCachedDocument('users', $user->getId());

                $events
                    ->setParam('userId', $user->getId())
                    ->setParam('sessionId', $session->getId())
                    ->setPayload($response->output($session, Response::MODEL_SESSION))
                ;
                return $response->noContent();
            }
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/sessions/:sessionId')
    ->desc('Update OAuth session (refresh tokens)')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].update')
    ->label('audits.event', 'session.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateSession')
    ->label('sdk.description', '/docs/references/account/update-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to update the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('events')
    ->action(function (?string $sessionId, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Event $events) {
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret, $authDuration)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                // Comment below would skip re-generation if token is still valid
                // We decided to not include this because developer can get expiration date from the session
                // I kept code in comment because it might become relevant in the future

                // $expireAt = (int) $session->getAttribute('providerAccessTokenExpiry');
                // if(\time() < $expireAt - 5) { // 5 seconds time-sync and networking gap, to be safe
                //     return $response->noContent();
                // }

                $provider = $session->getAttribute('provider');
                $refreshToken = $session->getAttribute('providerRefreshToken');

                $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
                $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

                $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

                if (!\class_exists($className)) {
                    throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
                }

                $oauth2 = new $className($appId, $appSecret, '', [], []);

                $oauth2->refreshTokens($refreshToken);

                $session
                    ->setAttribute('providerAccessToken', $oauth2->getAccessToken(''))
                    ->setAttribute('providerRefreshToken', $oauth2->getRefreshToken(''))
                    ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

                $dbForProject->updateDocument('sessions', $sessionId, $session);

                $dbForProject->deleteCachedDocument('users', $user->getId());

                $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;

                $session->setAttribute('expire', DateTime::formatTz(DateTime::addSeconds(new \DateTime($session->getCreatedAt()), $authDuration)));

                $events
                    ->setParam('userId', $user->getId())
                    ->setParam('sessionId', $session->getId())
                    ->setPayload($response->output($session, Response::MODEL_SESSION))
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    });

App::delete('/v1/account/sessions')
    ->desc('Delete sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('usage.metric', 'sessions.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/account/delete-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('events')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Event $events) {

        $protocol = $request->getProtocol();
        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) {/** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());

            if (!Config::getParam('domainVerification')) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([]));
            }

            $session
                ->setAttribute('current', false)
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')))
            ;

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) {
                $session->setAttribute('current', true);
                $session->setAttribute('expire', DateTime::addSeconds(new \DateTime($session->getCreatedAt()), Auth::TOKEN_EXPIRATION_LOGIN_LONG));

                 // If current session delete the cookies too
                $response
                    ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'));

                // Use current session for events.
                $events->setPayload($response->output($session, Response::MODEL_SESSION));
            }
        }

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId());

        $response->noContent();
    });

App::post('/v1/account/recovery')
    ->desc('Create password recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].recovery.[tokenId].create')
    ->label('audits.event', 'recovery.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'ip:{ip}'])
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn ($clients) => new Host($clients), 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('mails')
    ->inject('events')
    ->action(function (string $email, string $url, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Mail $mails, Event $events) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $email = \strtolower($email);

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        if (!$profile) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttributes($profile->getArrayCopy());

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_RECOVERY);

        $secret = Auth::tokenGenerator();
        $recovery = new Document([
            '$id' => ID::unique(),
            'userId' => $profile->getId(),
            'userInternalId' => $profile->getInternalId(),
            'type' => Auth::TOKEN_TYPE_RECOVERY,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole(Role::user($profile->getId())->toString());

        $recovery = $dbForProject->createDocument('tokens', $recovery
            ->setAttribute('$permissions', [
                Permission::read(Role::user($profile->getId())),
                Permission::update(Role::user($profile->getId())),
                Permission::delete(Role::user($profile->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getId(), 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');
        $body = $locale->getText("emails.recovery.body");
        $subject = $locale->getText("emails.recovery.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.recovery-' . $locale->default] ?? [];

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message->setParam('{{body}}', $body);
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $mails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $mails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'subject' => $subject,
            'hello' => $locale->getText("emails.recovery.hello"),
            'body' => $body,
            'footer' => $locale->getText("emails.recovery.footer"),
            'thanks' => $locale->getText("emails.recovery.thanks"),
            'signature' => $locale->getText("emails.recovery.signature"),
            'direction' => $locale->getText('settings.direction'),
            'bg-body' => '#f7f7f7',
            'bg-content' => '#ffffff',
            'text-content' => '#000000',
            /* {{user}} ,{{team}}, {{project}} and {{redirect}} are required in the templates */
            'user' => $profile->getAttribute('name'),
            'team' => '',
            'project' => $projectName,
            'redirect' => $url
        ];


        $mails
            ->setRecipient($profile->getAttribute('email', ''))
            ->setName($profile->getAttribute('name'))
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setSubject($subject)
            ->trigger();

        $events
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recovery->getId())
            ->setUser($profile)
            ->setPayload($response->output(
                $recovery->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $recovery->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN);
    });

App::put('/v1/account/recovery')
    ->desc('Create password recovery (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].recovery.[tokenId].update')
    ->label('audits.event', 'recovery.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateRecovery')
    ->label('sdk.description', '/docs/references/account/update-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid reset token.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->param('passwordAgain', '', new Password(), 'Repeat new user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('events')
    ->action(function (string $userId, string $secret, string $password, string $passwordAgain, Response $response, Document $user, Database $dbForProject, Document $project, Event $events) {
        if ($password !== $passwordAgain) {
            throw new Exception(Exception::USER_PASSWORD_MISMATCH);
        }

        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $recovery = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_RECOVERY, $secret);

        if (!$recovery) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole(Role::user($profile->getId())->toString());

        $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);

        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $profile->getAttribute('passwordHistory', []);
        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $profile->getAttribute('hash'), $profile->getAttribute('hashOptions'));
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile
                ->setAttribute('password', $newPassword)
                ->setAttribute('passwordHistory', $history)
                ->setAttribute('passwordUpdate', DateTime::now())
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('emailVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $recoveryDocument = $dbForProject->getDocument('tokens', $recovery);

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $recovery);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $events
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
        ;

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification')
    ->desc('Create email verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createVerification')
    ->label('sdk.description', '/docs/references/account/create-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('url', '', fn($clients) => new Host($clients), 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('events')
    ->inject('mails')
    ->action(function (string $url, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Locale $locale, Event $events, Mail $mails) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $verificationSecret = Auth::tokenGenerator();
        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_VERIFICATION,
            'secret' => Auth::hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole(Role::user($user->getId())->toString());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');
        $body = $locale->getText("emails.verification.body");
        $subject = $locale->getText("emails.verification.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.verification-' . $locale->default] ?? [];

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message->setParam('{{body}}', $body);
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $mails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $mails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'subject' => $subject,
            'hello' => $locale->getText("emails.verification.hello"),
            'body' => $body,
            'footer' => $locale->getText("emails.verification.footer"),
            'thanks' => $locale->getText("emails.verification.thanks"),
            'signature' => $locale->getText("emails.verification.signature"),
            'direction' => $locale->getText('settings.direction'),
            'bg-body' => '#f7f7f7',
            'bg-content' => '#ffffff',
            'text-content' => '#000000',
            /* {{user}} ,{{team}}, {{project}} and {{redirect}} are required in the templates */
            'user' => $user->getAttribute('name'),
            'team' => '',
            'project' => $projectName,
            'redirect' => $url
        ];

        $mails
            ->setSubject($subject)
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setRecipient($user->getAttribute('email'))
            ->setName($user->getAttribute('name') ?? '')
            ->trigger();

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $verificationSecret),
                Response::MODEL_TOKEN
            ));

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $verificationSecret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

App::put('/v1/account/verification')
    ->desc('Create email verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/account/update-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Event $events) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $verification = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_VERIFICATION, $secret);

        if (!$verification) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('emailVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $verificationDocument = $dbForProject->getDocument('tokens', $verification);

        /**
         * We act like we're updating and validating
         *  the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verification);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $events
            ->setParam('userId', $userId)
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification/phone')
    ->desc('Create phone verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneVerification')
    ->label('sdk.description', '/docs/references/account/create-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{userId}')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('messaging')
    ->inject('project')
    ->inject('locale')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Event $events, EventPhone $messaging, Document $project, Locale $locale) {

        if (empty(App::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED);
        }

        if (empty($user->getAttribute('phone'))) {
            throw new Exception(Exception::USER_PHONE_NOT_FOUND);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $secret = Auth::codeGenerator();
        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => Auth::hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole(Role::user($user->getId())->toString());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

        $customTemplate = $project->getAttribute('templates', [])['sms.verification-' . $locale->default] ?? [];
        if (!empty($customTemplate)) {
            $message = $customTemplate['message'] ?? $message;
        }

        $message = $message->setParam('{{token}}', $secret);
        $message = $message->render();

        $messaging
            ->setRecipient($user->getAttribute('phone'))
            ->setMessage($message)
            ->trigger()
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

App::put('/v1/account/verification/phone')
    ->desc('Create phone verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhoneVerification')
    ->label('sdk.description', '/docs/references/account/update-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Event $events) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verification = Auth::phoneTokenVerify($user->getAttribute('tokens', []), $secret);

        if (!$verification) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('phoneVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $verificationDocument = $dbForProject->getDocument('tokens', $verification);

        /**
         * We act like we're updating and validating the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verification);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });
