<?php
declare(strict_types=1);

/**
 * The starting template: the DreamHub E3 New Associate Action Plan, with suggested weeks.
 * Edited later on the Action Plan > Template screen; this runs only while the template is empty.
 * Each item: [title, details, week_from, week_to, 'tick'|'count', target|null, tool_key|null, auto_key|null]
 * auto_key: counted by itself from the toolkit (see actionplan_auto.php); for a tick item it only shows a number beside it.
 */
function ap_seed_data(): array
{
    return [
        'Preparation' => [
            ['3-year career planning: questionnaire', 'Fill in the career planning questionnaire.', 1, 1, 'tick', null, null],
            ['Commission structure', 'Understand how commission and the career path work.', 1, 1, 'tick', null, 'million-income'],
            ['Dream Board', null, 1, 1, 'tick', null, null],
            ['Mission, Vision and Core Values', null, 1, 1, 'tick', null, null],
            ['Mindset', '產品觀, 事業觀, 意識情緒, 名單經營, 銷售流程, 招聘流程, 自我定位', 1, 1, 'tick', null, null],
            ['Family Visit', null, 1, 1, 'tick', null, null],
            ['Business card', null, 1, 1, 'tick', null, null],
            ['iPad + Apple Pencil', null, 1, 1, 'tick', null, null],
            ['Attend COP', 'Self positioning; career blueprint.', 1, 1, 'tick', null, null],
            ['一套四式', '走動管理, 成功寶典, SAP, 三訪列車', 1, 1, 'tick', null, null],
        ],
        'Knowledge' => [
            ['PEP Year 1: M1, M2, M3', null, 1, 6, 'tick', null, null],
            ['TCF courses', '04343, 03661, 04401, 04551, 04121 and the rest of the list.', 1, 6, 'tick', null, null],
            ['BTC M1, M2, M3, M4', null, 1, 6, 'tick', null, null],
            ['Quick Start', null, 1, 6, 'tick', null, null],
            ['Agency morning and night meetings', null, 1, 6, 'tick', null, null],
            ['DL1 interview', null, 1, 6, 'tick', null, null],
            ['Video: Customer Fact Finding Form', null, 1, 6, 'tick', null, null],
            ['Video: Medical Exclusion', null, 1, 6, 'tick', null, null],
            ['Video: Medical Repricing', null, 1, 6, 'tick', null, null],
            ['Video: Insurance Concept', null, 1, 6, 'tick', null, null],
            ['Product knowledge: Medical, CI, Life', null, 2, 6, 'tick', null, 'sum-assured'],
        ],
        'Kick Start' => [
            ['Prospect list (100)', 'Name, relationship, location, age, occupation, category.', 1, 2, 'count', 100, 'client-portal', 'prospects'],
            ['Categorise the list', 'Trust vs capability, 9宮格, 6同.', 1, 2, 'tick', null, null],
            ['定聯', null, 1, 2, 'tick', null, null],
            ['Join DL2', null, 1, 2, 'tick', null, null],
            ['Buddy system: buddy assigned', null, 1, 2, 'tick', null, null],
            ['Start the 3V report', null, 1, 2, 'tick', null, null],
            ['Start the point system', null, 1, 2, 'tick', null, null],
        ],
        'Practical' => [
            ['Customer fact finding forms (30)', null, 3, 12, 'count', 30, 'protection-needs'],
            ['Questioning technique', null, 3, 6, 'tick', null, null],
            ['Natural market survey (70)', null, 3, 12, 'count', 70, 'client-portal', 'open'],
            ['Presentations (50)', null, 3, 12, 'count', 50, 'client-portal', 'present'],
            ['New business (3)', null, 3, 12, 'count', 3, 'client-portal', 'closes'],
            ['Sales cycle and presentation training', null, 3, 4, 'tick', null, null],
            ['Fact finding skills enhancement', null, 3, 6, 'tick', null, null],
            ['Objection handling', 'No hurry, no trust, no money, no authority, no needs.', 5, 8, 'tick', null, null],
            ['Sales Builder Team', null, 6, 12, 'tick', null, null],
            ['Cold market (30)', null, 6, 12, 'count', 30, null],
            ['Referrals (20)', null, 6, 12, 'count', 20, null],
            ['Open market skill', null, 6, 12, 'tick', null, null],
        ],
        'Talent Acquisition' => [
            ['Career Introduction 1+2', null, 8, 12, 'tick', null, 'career-code', 'career_added'],
        ],
        'After-sales' => [
            ['Policy delivery', 'Use the delivery template and checklist.', 6, 13, 'tick', null, null],
            ['Policy summary sheet', null, 6, 13, 'tick', null, 'policy-summary'],
            ['ePolicy: open the customer folder and save details', null, 6, 13, 'tick', null, null],
        ],
        'Review' => [
            ['Day-90 review with your leader', null, 13, 13, 'tick', null, null],
        ],
    ];
}
