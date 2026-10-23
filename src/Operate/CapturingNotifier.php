<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;

/** Dry-run notifier: prints each message's exact text under a DRY RUN label, posts nothing, never reads the webhook. */
final class CapturingNotifier implements Notifier
{
    /** @var list<Message> */
    private array $messages = [];

    /** @param callable(string): void $out receives the text to print (e.g. fn ($s) => fwrite(STDOUT, $s)) */
    public function __construct(private readonly mixed $out)
    {
    }

    public function send(Message $message, Destination $to): void
    {
        $this->messages[] = $message;
        // The digest renderer opens with a header section; notices are a single `notice` section.
        $kind = in_array('header', array_column($message->sections, 'kind'), true) ? 'digest' : 'notice';
        $text = $message->text;
        ($this->out)("== DRY RUN message to $kind ==\n" . $text . (str_ends_with($text, "\n") ? '' : "\n"));
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return $this->messages;
    }
}
