<?php

declare(strict_types=1);

namespace Webreboot\GdeSlon\Interface\Mcp;

use Webreboot\GdeSlon\Interface\Mcp\Tool\GetCategoriesTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\GetCouponTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\GetLostOrderClaimTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\GetMerchantTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListCouponKindsTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListCouponsTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListLostOrderClaimsTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListMerchantCategoriesTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListMerchantsTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\ListOrdersTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\SearchOffersTool;
use Webreboot\GdeSlon\Interface\Mcp\Tool\Tool;

/**
 * Инструменты сервера в постоянном порядке и их описания для tools/list.
 *
 * @internal
 */
final class ToolCatalog
{
    /** @var array<string, Tool> */
    private readonly array $tools;

    /**
     * @param list<Tool> $tools
     */
    public function __construct(array $tools)
    {
        $byName = [];
        foreach ($tools as $tool) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,128}\z/', $tool->name()) !== 1 || isset($byName[$tool->name()])) {
                throw new \LogicException(sprintf('Имя инструмента «%s» недопустимо или повторяется', $tool->name()));
            }
            $byName[$tool->name()] = $tool;
        }
        $this->tools = $byName;
    }

    /**
     * Инструменты сервера `gdeslon mcp` (только чтение) — в порядке docs/mcp.md. С --reveal-links описания купонных
     * инструментов предупреждают модель о токене в ссылках.
     */
    public static function standard(bool $revealLinks = false): self
    {
        return new self([
            new GetCategoriesTool(),
            new ListMerchantsTool(),
            new GetMerchantTool(),
            new ListMerchantCategoriesTool(),
            new SearchOffersTool(),
            new ListOrdersTool(),
            new ListLostOrderClaimsTool(),
            new GetLostOrderClaimTool(),
            new ListCouponsTool($revealLinks),
            new GetCouponTool($revealLinks),
            new ListCouponKindsTool(),
        ]);
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @param bool $structured outputSchema объявляется, только если клиент примет structuredContent (≥ 2025-06-18):
     *                         при outputSchema сервер обязан его отдавать
     *
     * @return list<\stdClass>
     */
    public function definitions(bool $structured): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $input = ['type' => 'object', 'properties' => Json::object($tool->inputProperties())];
            if ($tool->required() !== []) {
                $input['required'] = $tool->required();
            }
            $input['additionalProperties'] = false;

            $definition = [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'inputSchema' => Json::object($input),
            ];
            if ($structured) {
                $definition['outputSchema'] = OutputSchema::of($tool->outputShape());
            }
            $definition['annotations'] = Json::object([
                'title' => $tool->title(),
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ]);
            $definitions[] = Json::object($definition);
        }

        return $definitions;
    }
}
