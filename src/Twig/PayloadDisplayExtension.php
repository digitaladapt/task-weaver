<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\PayloadRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class PayloadDisplayExtension extends AbstractExtension
{
    public function __construct(
        private readonly PayloadRenderer $renderer,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('payload_prepare', $this->prepare(...)),
        ];
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return array{json: array<string, mixed>, sections: array<int, array{path: string, html: string}>}
     */
    public function prepare(?array $value, string $label = ''): array
    {
        return $this->renderer->prepare($value, $label);
    }
}
