<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Event;
use App\Entity\Step;
use App\Entity\Task;
use App\Entity\Worker;

use function assert;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin page / task show: payload/result display.
 *
 * The JSON view wraps (no horizontal scroll), and rich string leaves are
 * extracted into a "rendered content" section — markdown is rendered, raw
 * HTML/XSS attempts are escaped/removed, and NOTHING appears both in the
 * JSON view and the rendered section (no duplicated content).
 */
final class TaskShowRenderingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        assert($this->em instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->authenticate();
    }

    private function authenticate(): void
    {
        // Bootstrap session with the admin API key (same pattern as
        // AdminAuthFlowTest).
        $this->client->request('POST', '/api/auth/setup');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $key = (string) ($data['api_key'] ?? '');
        $this->client->request('POST', '/api/auth/verify', server: ['HTTP_X_API_KEY' => $key]);
    }

    public function testTaskShowRendersMarkdownAndEscapesHtml(): void
    {
        $task = new Task('Rendering test', 'Verify payload rendering.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $this->em->persist($task);

        $step = new Step('Result step', 'Has a markdown result.');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setResult([
            'answer' => "# Answer\n\n**bold** body that is long enough to be extracted.\n\n".str_repeat('More text to ensure threshold. ', 3),
            'count' => 3,
        ]);
        $task->addStep($step);

        $worker = new Worker('worker-1', ['terminal']);
        $this->em->persist($worker);

        $event = new Event($step, $task, $worker, Event::TYPE_TOOL_FINISHED);
        $event->setPayload([
            'tool' => 'weather.get',
            'ok' => true,
            'result' => [
                'content' => "**Summary** for London: mostly sunny.\n\n<script>alert('xss')</script>\n\n".str_repeat('extra words to reach the rich threshold. ', 3),
            ],
        ]);
        $this->em->persist($event);
        $this->em->flush();

        $this->client->request('GET', '/tasks/'.$task->getId()->toRfc4122());

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // The rendered markdown appears.
        self::assertStringContainsString('<h1>Answer</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);

        // Raw script never appears as an executable tag.
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);

        // The JSON view contains a placeholder marker (no duplicated content),
        // and the rendered section shows the path.
        self::assertStringContainsString('[rendered', $html);
        self::assertStringContainsString('result.answer', $html);
        self::assertStringContainsString('event.payload.result.content', $html);

        // Non-rich leaves remain in the JSON view (Twig escapes the JSON, so
        // quotes come out as entities in the rendered page).
        self::assertStringContainsString('&quot;count&quot;: 3', $html);
        self::assertStringContainsString('&quot;tool&quot;: &quot;weather.get&quot;', $html);

        // JSON view uses wrapping class (no horizontal scroll).
        self::assertStringContainsString('json-block', $html);
    }

    public function testStepShowsBothClocksAndIdleCountdown(): void
    {
        $task = new Task('Liveness display', 'Two clocks on the step card.');
        $task->setStatus(Task::STATUS_RUNNING);
        $this->em->persist($task);

        $step = new Step('Long step', 'Currently running.');
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_RUNNING);
        $step->setExpiresAt(new DateTimeImmutable('+10 minutes'));
        $step->setIdleExpiresAt(new DateTimeImmutable('+90 seconds'));
        $task->addStep($step);
        $this->em->flush();

        $this->client->request('GET', '/tasks/'.$task->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        // Both clocks are surfaced, so an operator can tell a slow-but-alive
        // step (idle keeps moving) from a wedged one (idle converges on
        // deadline).
        self::assertStringContainsString('deadline ', $html);
        self::assertStringContainsString('idle until ', $html);
        self::assertStringContainsString('s of quiet left', $html);
    }

    public function testStepShowsPartialBadgeWithReason(): void
    {
        $task = new Task('Truncated task', 'A step hit a budget.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $this->em->persist($task);

        $step = new Step('Truncated step', 'Ran out of budget.');
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setResult(['summary' => 'Half an answer']);
        $step->setPartial(true, 'idle timeout: LLM silent 105s');
        $task->addStep($step);
        $this->em->flush();

        $this->client->request('GET', '/tasks/'.$task->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('partial</span>', $html);
        // The reason rides in the tooltip so the operator can see WHY without
        // digging through the event log.
        self::assertStringContainsString('idle timeout: LLM silent 105s', $html);
    }

    public function testOrdinaryStepHasNoPartialBadge(): void
    {
        $task = new Task('Normal task', 'Nothing truncated.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $this->em->persist($task);

        $step = new Step('Normal step', 'Finished cleanly.');
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setResult(['summary' => 'Whole answer']);
        $task->addStep($step);
        $this->em->flush();

        $this->client->request('GET', '/tasks/'.$task->getId()->toRfc4122());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('>partial<', $html);
    }

    public function testNoScriptDangerFromPayload(): void
    {
        $task = new Task('XSS task', 'Payload with script.');
        $task->setStatus(Task::STATUS_COMPLETED);
        $this->em->persist($task);

        $step = new Step('Step', 'Has script result.');
        $step->setIsFinal(true);
        $step->setSortOrder(0);
        $step->setStatus(Step::STATUS_COMPLETED);
        $step->setResult([
            'evil' => "<script>alert('pwned')</script><img src=x onerror=alert(1)>\n\n".str_repeat('padding to exceed threshold ', 3),
        ]);
        $task->addStep($step);
        $this->em->flush();

        $this->client->request('GET', '/tasks/'.$task->getId()->toRfc4122());

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('onerror=', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }
}
