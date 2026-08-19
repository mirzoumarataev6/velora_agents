<?php

namespace App\Console\Commands;

use App\Exceptions\GeminiException;
use App\Services\Llm\GeminiProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gemini:test')]
#[Description('Smoke-test the Gemini API key against generateContent and embedContent')]
class GeminiTestCommand extends Command
{
    public function handle(GeminiProvider $gemini): int
    {
        $this->components->info('Testing Gemini generateContent...');

        try {
            $result = $gemini->generate(
                'You are a terse assistant. Answer in one short sentence.',
                [['role' => 'user', 'text' => 'Say hello and name the current Gemini model you are.']]
            );

            $this->line("  Reply: {$result['text']}");
            $this->line("  Tokens: input={$result['input_tokens']} output={$result['output_tokens']} finish={$result['finish_reason']}");
            $this->components->info('generateContent OK');
        } catch (GeminiException $e) {
            $this->components->error('generateContent FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Testing Gemini embedContent...');

        try {
            $embedding = $gemini->embed('Red cotton dress, size M, price 250 somoni, 12 in stock.');
            $dimensions = count($embedding['vector']);

            $this->line("  Vector dimensions: {$dimensions}");
            $this->line('  First 5 values: '.implode(', ', array_map(
                fn ($v) => round($v, 4),
                array_slice($embedding['vector'], 0, 5)
            )));
            $this->components->info('embedContent OK');
        } catch (GeminiException $e) {
            $this->components->error('embedContent FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Gemini API key is working.');

        return self::SUCCESS;
    }
}
