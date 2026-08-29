<?php
/**
 * Tests for the chatbot's grounding layer — the knowledge base, the system
 * prompt built from it, the gate backstop, and the Gemini request config.
 *
 * None of this needs a database or an API key, which is the point: these are
 * the parts that decide whether the bot answers accurately, and they can be
 * checked on every PR. The DB-bound request flow in chat.php is not covered
 * here.
 *
 * Run:  php ci/chatbot-content-test.php
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/chatbot/api/prompt.php';
require_once $repoRoot . '/chatbot/api/gemini.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/* -- Knowledge base ------------------------------------------------------ */

echo "knowledge base\n";

$knowledgeDir = $repoRoot . '/chatbot/knowledge';
$files = glob($knowledgeDir . '/*.md') ?: [];
check('knowledge files exist', count($files) >= 5, count($files) . ' found');

// The whole file is pasted into the system prompt, so an unfinished
// placeholder is not a note to the content team — it is something the model
// will happily read out to a visitor ("Audience: [TODO]").
foreach ($files as $file) {
    $name = basename($file);
    $body = (string) file_get_contents($file);
    check("$name has no TODO markers", stripos($body, 'TODO') === false);
    check("$name has no bracketed placeholders",
        preg_match('/\[[^\]\n]{0,80}\]/', $body) !== 1,
        'found ' . (preg_match('/(\[[^\]\n]{0,80}\])/', $body, $m) ? $m[1] : ''));
}

$allKnowledge = '';
foreach ($files as $file) $allKnowledge .= (string) file_get_contents($file);
check('knowledge fits the prompt budget',
    strlen($allKnowledge) <= CHATBOT_KNOWLEDGE_CHAR_BUDGET,
    strlen($allKnowledge) . ' / ' . CHATBOT_KNOWLEDGE_CHAR_BUDGET . ' chars');

/* -- The gate is structural, not a prompt instruction -------------------- */

echo "gate: knowledge withholding\n";

[$locked, ] = chatbot_load_knowledge(false);
[$unlocked, ] = chatbot_load_knowledge(true);
$gatedBody = (string) file_get_contents($knowledgeDir . '/' . CHATBOT_GATED_KNOWLEDGE_FILE);

check('gated file is present on disk', $gatedBody !== '');
check('locked knowledge excludes the gated file',
    strpos($locked, 'Corporate / institutional partnerships') === false);
check('unlocked knowledge includes the gated file',
    strpos($unlocked, 'Corporate / institutional partnerships') !== false);
check('sponsorship amounts never reach a locked prompt',
    strpos($locked, '$25 = one learner') === false);
check('locked knowledge still answers ordinary questions',
    strpos($locked, 'How to apply') !== false && strpos($locked, 'pre-training') !== false);

$lockedPrompt = chatbot_build_system_prompt(['gate_state' => 'none']);
$passedPrompt = chatbot_build_system_prompt(['gate_state' => 'passed']);
check('locked prompt is marked GATE: LOCKED', strpos($lockedPrompt, 'GATE: LOCKED') !== false);
check('passed prompt is marked GATE: PASSED', strpos($passedPrompt, 'GATE: PASSED') !== false);
check('locked prompt carries no partnership content',
    strpos($lockedPrompt, 'admin@ethioware.org') === false);

/* -- Prompt rules -------------------------------------------------------- */

echo "system prompt\n";

$prompt = chatbot_build_system_prompt([]);
check('instructs the model to ignore unfinished placeholders',
    stripos($prompt, 'placeholder') !== false && stripos($prompt, 'square brackets') !== false);
check('forbids quoting volatile dates and prices from memory',
    stripos($prompt, 'change every cohort') !== false);
check('keeps Research Scholars off the /apply form',
    strpos($prompt, 'does NOT use the /apply form') !== false);
check('lets a learner-cost question through the gate',
    stripos($prompt, 'NOT gated') !== false);
check('asks for plain-text links, not markdown',
    stripos($prompt, 'no markdown link syntax') !== false);
check('lists every program the schema allows',
    strpos($prompt, 'Software Engineering Basics') !== false
    && strpos($prompt, 'Research Scholars Program') !== false);

$withLead = chatbot_build_system_prompt(['name' => 'Ada', 'email' => 'ada@example.com']);
check('known visitor details are passed in',
    strpos($withLead, 'name=Ada') !== false && strpos($withLead, 'email=ada@example.com') !== false);
check('and the model is told not to re-ask',
    strpos($withLead, 'Never re-ask') !== false);
// Scoped to the KNOWLEDGE section: the RULES above it deliberately mention
// "TODO" while telling the model to ignore such lines.
$knowledgeSection = substr($prompt, (int) strpos($prompt, 'KNOWLEDGE:'));
check('no leftover placeholders in the prompt\'s knowledge section',
    stripos($knowledgeSection, 'TODO') === false);

/* -- Program names stay in sync ------------------------------------------ */

echo "program name sync\n";

$schemaPrograms = CHATBOT_GEMINI_RESPONSE_SCHEMA['properties']['program']['enum'];
$expected = array_merge(CHATBOT_PROGRAMS, ['']);
check('gemini schema enum matches CHATBOT_PROGRAMS',
    array_values(array_diff($expected, $schemaPrograms)) === []
    && array_values(array_diff($schemaPrograms, $expected)) === [],
    implode('|', $schemaPrograms));

$applyHtml = (string) file_get_contents($repoRoot . '/apply.html');
foreach (['Software Engineering Basics', 'Engineering Basics', 'Law Basics', 'Medicine Basics'] as $program) {
    check("apply.html still offers \"$program\"", strpos($applyHtml, $program) !== false);
    check("knowledge describes \"$program\"", strpos($allKnowledge, $program) !== false);
}

/* -- Gate keyword backstop ----------------------------------------------- */

echo "gate keyword backstop\n";

$shouldGate = [
    'I want to partner with Ethioware',
    'Tell me about partnership tiers',
    'We are interested in partnering',
    'Can my company sponsor a learner?',
    'What are your sponsorship options',
    'I would like to donate',
    'How do donations work?',
    'Is Ethioware taking investment?',
    'I am an investor looking at EdTech',
    'What is your pricing?',
    'Where does your funding come from',
    'We run a fundraising foundation',
    'Who funds the program?',
];
foreach ($shouldGate as $message) {
    check('gates: "' . $message . '"', chatbot_gate_keyword_hit($message) === true);
}

// The regression that motivated word-anchoring: these are ordinary learner
// questions that an earlier substring match sent to a partnerships form.
$shouldNotGate = [
    'Do you teach programming fundamentals?',
    'I want to learn the fundamentals of law',
    'How do I investigate my research question?',
    'The investigation methods confused me',
    'How much does the program cost?',
    'Is it free to apply?',
    'What are the fees for students?',
    'Can I apply to two programs?',
    'When does the next cohort start?',
    'Tell me about Medicine Basics',
];
foreach ($shouldNotGate as $message) {
    check('does not gate: "' . $message . '"', chatbot_gate_keyword_hit($message) === false);
}

/* -- Gemini request config ----------------------------------------------- */

echo "gemini request config\n";

$flash = chatbot_gemini_generation_config('gemini-2.5-flash');
check('thinking disabled on 2.5 Flash',
    ($flash['thinkingConfig']['thinkingBudget'] ?? null) === 0);
check('thinking disabled on 2.5 Flash Lite',
    (chatbot_gemini_generation_config('gemini-2.5-flash-lite')['thinkingConfig']['thinkingBudget'] ?? null) === 0);
check('2.5 Pro is left alone (it cannot disable thinking)',
    !isset(chatbot_gemini_generation_config('gemini-2.5-pro')['thinkingConfig']));
check('output budget leaves room for a full reply',
    ($flash['maxOutputTokens'] ?? 0) >= 800);
check('structured output is still requested',
    ($flash['responseMimeType'] ?? '') === 'application/json'
    && isset($flash['responseSchema']));

echo "truncated-response salvage\n";

$intact = '{"reply": "Applying takes 3-5 minutes at /apply.", "intent": "enrollment", "action": "none"}';
$cutOff = '{"reply": "Applying takes 3-5 minutes at /apply.", "intent": "enro';
$salvaged = chatbot_gemini_salvage($cutOff);
check('recovers the reply from JSON cut off after it',
    is_array($salvaged) && $salvaged['reply'] === 'Applying takes 3-5 minutes at /apply.',
    var_export($salvaged, true));
check('salvaged payload is schema-shaped',
    is_array($salvaged) && isset($salvaged['intent'], $salvaged['action'])
    && $salvaged['action'] === 'none');
check('handles escaped quotes inside the reply',
    (chatbot_gemini_salvage('{"reply": "She said \"hello\" first.", "int') ?? [])['reply']
        === 'She said "hello" first.');
check('gives up when the reply itself is truncated',
    chatbot_gemini_salvage('{"reply": "Applying takes 3-5 minu') === null);
check('gives up when there is no reply field',
    chatbot_gemini_salvage('{"intent": "general", "action": "none"}') === null);
check('intact JSON is unaffected by the salvage path',
    is_array(json_decode($intact, true)) && isset(json_decode($intact, true)['reply']));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
