<?php

namespace Packstub\SessionReplay\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Packstub\Holler\Facades\Holler;
use Packstub\Holler\HollerServiceProvider;
use Packstub\Holler\Models\Call;
use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Tests\Fixtures\Models\Team;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

/**
 * Assist on (the routes register at boot), with packstub/holler (require-dev) booted when it is installed. Tests that
 * need Holler call needsCalls() and are skipped without it.
 */
abstract class AssistTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...(class_exists(HollerServiceProvider::class) ? [HollerServiceProvider::class] : []),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session-replay.assist.enabled', true);
    }

    protected function needsCalls(): void
    {
        if (! class_exists(HollerServiceProvider::class)) {
            $this->markTestSkipped('packstub/holler is not installed.');
        }
    }

    /** The queue alone, as without packstub/holler. */
    protected function withoutCalls(): void
    {
        config()->set('session-replay.assist.calls', false);
    }

    /** Staff may watch (and so assist), customers may not. */
    protected function staffGate(): void
    {
        Gate::define('viewSessionReplay', fn ($user, ?ReplaySession $session = null) => (bool) $user->is_staff);
    }

    protected function staff(array $attributes = []): User
    {
        return $this->user($attributes + ['name' => 'Ana Support', 'is_staff' => true]);
    }

    /**
     * A recording made by this person's browser, with the write key only that browser holds.
     *
     * @return array{session: ReplaySession, token: string, key: string}
     */
    protected function browser(?User $user = null, array $attributes = []): array
    {
        $token = $this->token($user ?? $this->user());
        $key = bin2hex(random_bytes(16));
        $id = (string) Str::uuid();

        $this->ingest(['token' => $token, 'session' => $id, 'key' => $key])->assertCreated();

        $session = ReplaySession::query()->findOrFail($id);

        if ($attributes !== []) {
            $session->forceFill($attributes)->save();
        }

        return ['session' => $session, 'token' => $token, 'key' => $key];
    }

    /**
     * A recording of a person (or a guest) of this workspace, as the body of a request from it.
     *
     * @return array{token: string, session: string, key: string}
     */
    protected function triple(?Team $team = null, bool $guest = false): array
    {
        $token = $this->token($guest ? null : $this->user(), $team);
        $key = bin2hex(random_bytes(16));
        $id = (string) Str::uuid();

        $this->ingest(['token' => $token, 'session' => $id, 'key' => $key])->assertCreated();

        return ['token' => $token, 'session' => $id, 'key' => $key];
    }

    /** @param  array{session: ReplaySession, token: string, key: string}  $browser */
    protected function ask(array $browser, array $data = []): TestResponse
    {
        return $this->postJson(route('session-replay.assist.store'), $data + [
            'token' => $browser['token'],
            'session' => $browser['session']->id,
            'key' => $browser['key'],
            'message' => 'The checkout button does nothing',
            'url' => 'https://shop.test/checkout?step=2&token=abc',
        ]);
    }

    /** @return array{id: string, secret: string} */
    protected function asked(array $browser, array $data = []): array
    {
        $response = $this->ask($browser, $data)->assertCreated();

        return ['id' => $response->json('id'), 'secret' => $response->json('secret')];
    }

    protected function poll(string $id, ?string $secret, string $token, array $data = []): TestResponse
    {
        return $this->postJson(route('session-replay.assist.poll', ['id' => $id]), array_filter(['secret' => $secret, 'token' => $token] + $data, fn ($value) => $value !== null));
    }

    protected function visitorPost(string $action, string $id, string $secret, string $token, array $data = []): TestResponse
    {
        return $this->postJson(route('session-replay.assist.'.$action, ['id' => $id]), $data + ['secret' => $secret, 'token' => $token]);
    }

    /**
     * A visitor's request, claimed by an agent who is signed in (to the viewer and to Holler: the same guard).
     *
     * @return array{0: array{session: ReplaySession, token: string, key: string}, 1: array{id: string, secret: string}, 2: User}
     */
    protected function claimed(): array
    {
        $browser = $this->browser();
        $asked = $this->asked($browser);
        $agent = $this->staff();

        $this->actingAs($agent)->postJson(route('session-replay.assist.claim', $asked['id']))->assertOk();

        return [$browser, $asked, $agent];
    }

    /**
     * A live assist with the visitor's page (tab-1) and the agent's stage (agent-tab) both on the call.
     *
     * @return array{0: array{session: ReplaySession, token: string, key: string}, 1: array{id: string, secret: string}, 2: User, 3: Call}
     */
    protected function liveAssist(): array
    {
        [$browser, $asked, $agent] = $this->claimed();

        $id = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active'])->json('call.id');
        Holler::poll((string) $agent->id, 0, $id, 'agent-tab', claim: true, kind: 'assist');

        return [$browser, $asked, $agent, Call::query()->with('participants')->findOrFail($id)];
    }

    /**
     * The agent's stage is open: it polls the request's status every 2 s (as the signed-in agent).
     *
     * @return array<string, mixed>
     */
    protected function stagePolls(array $asked): array
    {
        return $this->getJson(route('session-replay.assist.status', $asked['id']))->assertOk()->json();
    }

    /**
     * The visitor's page on the call and the agent's stage with it, then the agent leaves the live view: their side stops
     * polling Holler while the page keeps polling, until Holler drops the agent (holler.reconnect_timeout).
     *
     * @return array{0: array{session: ReplaySession, token: string, key: string}, 1: array{id: string, secret: string}, 2: User, 3: int, 4: TestResponse}
     */
    protected function agentLeavesTheLiveView(): array
    {
        config()->set('holler.reconnect_timeout', 6);
        $this->freezeSecond();

        [$browser, $asked, $agent] = $this->claimed();

        $call = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1'])->assertJson(['status' => 'active'])->json('call.id');
        Holler::poll((string) $agent->id, 0, $call, 'agent-tab', claim: true, kind: 'assist');

        // The agent navigates away; the visitor's page goes on polling every 2 s.
        for ($i = 0; $i < 4; $i++) {
            $this->travel(2)->seconds();
            $last = $this->poll($asked['id'], $asked['secret'], $browser['token'], ['tab' => 'tab-1']);
        }

        return [$browser, $asked, $agent, $call, $last];
    }
}
