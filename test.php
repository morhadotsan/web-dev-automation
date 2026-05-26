<?php
declare(strict_types=1);
require_once __DIR__."/root_functions.php";

$claudeBin = locate_claude_cli();
if ($claudeBin === null) {
    fwrite(STDERR, "error: claude CLI not found on PATH.\n");
    fwrite(STDERR, "       Install with: npm install -g @anthropic-ai/claude-code\n");
    exit(2);
}
echo "claude CLI : $claudeBin\n";

$model        = getenv("ANTHROPIC_MODEL") ?: "claude-sonnet-4-6";
$systemBlocks = [["type" => "text", "text" => "You are a terse assistant. Reply in one short sentence."]];
$userPrompt   = "Say hello and tell me what 2 + 2 equals.";

echo "model      : $model\n";
echo "prompt     : $userPrompt\n";
echo "\nCalling claude CLI ...\n";

$started = microtime(true);
[$response, $usage] = call_via_claude_cli($claudeBin, $model, $systemBlocks, $userPrompt);
$elapsed = microtime(true) - $started;

echo "\n--- response (" . strlen($response) . " chars in " . sprintf("%.1fs", $elapsed) . ") ---\n";
echo $response . "\n";
echo "--- end response ---\n";

if ($usage) {
    echo sprintf(
        "usage: in=%d, out=%d, cache_read=%d, cache_create=%d\n",
        $usage["input_tokens"],
        $usage["output_tokens"],
        $usage["cache_read_input_tokens"],
        $usage["cache_creation_input_tokens"]
    );
}
