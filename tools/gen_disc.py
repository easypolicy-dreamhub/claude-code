#!/usr/bin/env python3
"""Generates the DISC data files from the authored content below.

  python3 tools/gen_disc.py

Writes:
  private/data/disc.json                 which type each answer position belongs to (server only), thresholds
  private/data/disc_text.json            candidate result text + advisor notes, English and Chinese
  public_html/career-code/assets/disc-words.js   the 28 x 4 words shown to the candidate (no types, no logic)

All questions are original adjective sets in the general DISC style. They are not copied from any commercial test.
"""
import itertools, json, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = ['D', 'I', 'S', 'C']

# 28 words per type: (English, Chinese). Question n shows the n-th word of each type.
W = {
 'D': [('Direct','直接'),('Decisive','果断'),('Bold','大胆'),('Competitive','好胜'),('Determined','坚定'),('Driven','积极进取'),('Assertive','敢于表达'),
       ('Forceful','强势'),('Independent','独立'),('Results-focused','注重结果'),('Daring','敢于冒险'),('Take-charge','主动掌控'),('Demanding','要求高'),
       ('Fast-moving','行动迅速'),('Self-confident','自信'),('Ambitious','有野心'),('Persistent','坚持不懈'),('Straightforward','坦率'),
       ('Challenge-loving','喜欢挑战'),('Outspoken','直言不讳'),('Authoritative','有威严'),('Risk-taking','愿意冒险'),('Firm','坚决'),
       ('Goal-oriented','目标导向'),('Strong-willed','意志坚强'),('Quick to decide','快速决定'),('Commanding','有领导气势'),('Winning-minded','求胜心切')],
 'I': [('Enthusiastic','热情'),('Sociable','好交际'),('Persuasive','有说服力'),('Optimistic','乐观'),('Lively','活泼'),('Expressive','善于表达'),('Friendly','友善'),
       ('Inspiring','鼓舞人心'),('Charming','有魅力'),('Talkative','健谈'),('Cheerful','开朗'),('Outgoing','外向'),('Animated','生动'),
       ('Fun-loving','爱玩乐'),('Spontaneous','随性'),('Entertaining','风趣'),('Warm','亲切'),('Encouraging','爱鼓励别人'),
       ('Playful','俏皮'),('Popular','受欢迎'),('Upbeat','积极向上'),('Open','开放'),('Easy to talk to','平易近人'),
       ('High-spirited','兴致勃勃'),('Magnetic','有吸引力'),('Story-telling','擅长讲故事'),('Motivating','善于激励'),('Lighthearted','轻松随和')],
 'S': [('Patient','耐心'),('Calm','沉着'),('Supportive','乐于支持'),('Loyal','忠诚'),('Steady','稳定'),('Gentle','温和'),('Reliable','可靠'),
       ('Good listener','善于聆听'),('Agreeable','随和'),('Consistent','始终如一'),('Dependable','值得信赖'),('Considerate','体贴'),('Peaceful','爱好和平'),
       ('Accommodating','乐于配合'),('Understanding','善解人意'),('Easygoing','好相处'),('Modest','谦逊'),('Team-minded','重视团队'),
       ('Thoughtful','周到'),('Cooperative','合作'),('Humble','低调'),('Sincere','真诚'),('Kind-hearted','心地善良'),
       ('Content','知足'),('Steady-handed','做事稳妥'),('Soft-spoken','说话温柔'),('Caring','关心他人'),('Tolerant','宽容')],
 'C': [('Careful','谨慎'),('Precise','精确'),('Analytical','善于分析'),('Organised','有条理'),('Accurate','准确'),('Systematic','有系统'),('Cautious','小心'),
       ('Logical','有逻辑'),('Thorough','周全'),('Disciplined','自律'),('Detail-minded','注重细节'),('Exact','一丝不苟'),('Orderly','井然有序'),
       ('Fact-based','重视事实'),('Methodical','按部就班'),('Quality-minded','重视质量'),('Reserved','内敛'),('Rule-abiding','守规矩'),
       ('Questioning','爱追问'),('Prepared','准备充分'),('Perfectionist','追求完美'),('Objective','客观'),('Reflective','深思熟虑'),
       ('Standards-driven','坚持标准'),('Tidy','整洁'),('Planning-minded','重视规划'),('Fair-minded','公正'),('Double-checking','反复核对')],
}
assert all(len(W[t]) == 28 for t in T)
assert all(len({w[0] for w in W[t]}) == 28 for t in T)

TEXT = {
 'en': {
  'type': {
   'D': {'name': 'Dominance', 'tag': 'Direct and driven',
         'desc': 'You like to take charge, move quickly and get results. You are comfortable making decisions and facing challenges head on.',
         'strengths': ['Decisive under pressure', 'Confident setting goals', 'Quick to act on opportunities'],
         'work': 'You do your best work with clear goals, room to make decisions and a chance to see progress.',
         'tips': ['Slow down to hear how others see things before you decide.', 'Share the reasons behind your goals so people want to follow you.', 'Celebrate small wins along the way, not only the big result.'],
         'also': 'With a streak of Dominance, you also bring drive and a readiness to take the lead.',
         'talk': ['Be direct and brief; lead with the point and the outcome.', 'Offer choices and let them decide; avoid over-explaining.', 'Respect their time; keep meetings short and focused.'],
         'pressure': ['May become blunt, impatient or controlling.', 'May push ahead without bringing others along.'],
         'role': 'Advisory role: strong on drive, closing and leading a team. Watch listening, patience with detail and compliance routines.',
         'questions': ['Tell me about a goal you set and how you pushed through obstacles.', 'How do you handle a client who wants to go slower than you?', 'What do you do when you have to follow a rule you disagree with?']},
   'I': {'name': 'Influence', 'tag': 'Outgoing and inspiring',
         'desc': 'You enjoy people, ideas and energy. You tend to be friendly and persuasive, and you bring enthusiasm to what you do.',
         'strengths': ['Builds rapport quickly', 'Communicates with energy', 'Keeps others motivated'],
         'work': 'You do your best work with variety, people to talk to and recognition for your ideas.',
         'tips': ['Write down follow-up steps so good ideas turn into results.', 'Make time to listen as much as you talk.', 'Give detail and deadlines the same attention as the big picture.'],
         'also': 'With a streak of Influence, you also bring warmth and a natural ease with people.',
         'talk': ['Be warm and conversational; allow room for ideas and stories.', 'Give recognition and focus on the possibilities.', 'Confirm commitments in writing; they may agree with enthusiasm and forget the detail.'],
         'pressure': ['May talk more, lose focus or avoid hard conversations.', 'May over-promise to keep people happy.'],
         'role': 'Advisory role: strong on prospecting, rapport and presenting. Watch follow-through, admin discipline and consistency.',
         'questions': ['How do you keep in touch with people you have met only once?', 'Describe a time you missed a deadline and what you changed.', 'How would you build a client list from zero?']},
   'S': {'name': 'Steadiness', 'tag': 'Calm and dependable',
         'desc': 'You are patient, loyal and supportive. People trust you to be steady and to look after those around you.',
         'strengths': ['Excellent listener', 'Reliable and consistent', 'Builds long-term relationships'],
         'work': 'You do your best work in a stable, friendly team with clear expectations and time to settle into change.',
         'tips': ['Share your opinion early, even when it may cause friction.', 'Practise saying no kindly when your plate is full.', 'Treat change as a chance to grow, one step at a time.'],
         'also': 'With a streak of Steadiness, you also bring patience and genuine care for others.',
         'talk': ['Be calm and friendly; give time to think and avoid pressing for a decision on the spot.', 'Explain the why, the steps and the support they will get.', 'Ask for their view directly; they may not volunteer disagreement.'],
         'pressure': ['May go quiet, give in or avoid conflict.', 'May resist sudden change.'],
         'role': 'Advisory role: strong on client care, trust and long-term service. Watch cold prospecting, assertiveness and asking for the decision.',
         'questions': ['How do you feel about approaching people you do not know?', 'Tell me about a time you had to disagree with someone you respected.', 'How do you cope when plans change at short notice?']},
   'C': {'name': 'Conscientiousness', 'tag': 'Careful and accurate',
         'desc': 'You value quality, accuracy and good planning. You think things through and like to do them properly.',
         'strengths': ['Strong attention to detail', 'Thinks logically and plans ahead', 'Keeps high standards'],
         'work': 'You do your best work with clear information, time to prepare and standards you can rely on.',
         'tips': ['Decide with good-enough information; perfect information is rarely available.', 'Check in with people, not just the process.', 'Share your work earlier so others can help.'],
         'also': 'With a streak of Conscientiousness, you also bring care, structure and a good eye for detail.',
         'talk': ['Be precise and prepared; bring facts, examples and the details.', 'Give time to read and think; avoid rushing.', 'Be honest about limits; they notice gaps.'],
         'pressure': ['May become critical, withdrawn or over-analyse.', 'May delay deciding to avoid mistakes.'],
         'role': 'Advisory role: strong on product knowledge, compliance and planning. Watch outreach energy, speed of decision and warmth with new people.',
         'questions': ['How do you decide when you do not have all the information?', 'How comfortable are you with rejection and small talk with strangers?', 'Tell me about a time perfectionism slowed you down.']},
  },
  'ui': {
   'level': {'high': 'High', 'mid': 'Moderate', 'low': 'Low'},
   'flag': {
     'fast': 'Finished very quickly ({n} seconds). The answers may not have been considered.',
     'position': 'Chose the same answer position for most questions; may not have read each one carefully.',
     'extreme': 'A very one-sided profile; the person may have answered for an ideal role rather than for themselves.',
     'flat': 'A very even profile; the person may have hedged or found it hard to choose.'},
   'align': {
     'consistent': 'Career Code ({code}) and DISC ({style}) point the same way.',
     'partly': 'Partly consistent: Career Code ({code}) and DISC ({style}) overlap only in part. Ask how they see themselves.',
     'differs': 'The results differ: interests ({code}) and working style ({style}) do not line up in the usual way. Not a problem on its own; explore it in the interview.',
     'none': 'No Career Code result to compare with.'},
   'blend': '{a} with {b}',
  },
 },
 'zh': {
  'type': {
   'D': {'name': '支配型', 'tag': '直接、有冲劲',
         'desc': '你喜欢主导局面、行动迅速并追求成果。你善于做决定，也敢于正面迎接挑战。',
         'strengths': ['压力下果断', '善于设定目标', '能迅速把握机会'],
         'work': '目标明确、有决策空间、能看到进展时，你的表现最好。',
         'tips': ['决定之前先放慢一点，听听别人的看法。', '说明目标背后的原因，让人愿意跟随你。', '不只庆祝大成果，也要庆祝沿途的小成功。'],
         'also': '你也带有支配型的一面：有冲劲，也乐于带头。',
         'talk': ['直接简明，先讲重点和结果。', '提供选择，让对方自己决定，避免过度解释。', '尊重时间，会面短而聚焦。'],
         'pressure': ['可能变得直率、没耐心或控制欲强。', '可能不顾他人，一味向前冲。'],
         'role': '顾问角色：冲劲、成交和带团队是强项。需留意倾听、对细节的耐心和合规流程。',
         'questions': ['说说你设定过的一个目标，以及你如何克服障碍。', '遇到想慢慢来的客户，你怎么处理？', '遇到你不认同的规定，你怎么做？']},
   'I': {'name': '影响型', 'tag': '外向、有感染力',
         'desc': '你喜欢与人接触、交流想法，充满活力。你友善、有说服力，做事带着热情。',
         'strengths': ['很快与人建立关系', '沟通有活力', '能带动他人的积极性'],
         'work': '工作内容多变、有人可以交流、想法被认可时，你的表现最好。',
         'tips': ['把跟进步骤写下来，让好点子变成成果。', '多听少说，给别人说话的空间。', '对细节和期限，要和大局一样用心。'],
         'also': '你也带有影响型的一面：亲切，与人相处自然。',
         'talk': ['亲切、轻松地交谈，给他们空间分享想法和故事。', '多给认可，聚焦可能性。', '承诺要写下来确认；他们可能热情答应却忘记细节。'],
         'pressure': ['可能话变多、失去焦点或回避困难对话。', '为了让人开心而过度承诺。'],
         'role': '顾问角色：开拓客户、建立关系和讲解是强项。需留意跟进、行政纪律和稳定性。',
         'questions': ['你怎样与只见过一次的人保持联系？', '说说你错过期限的一次，以及你做了什么改变。', '你会如何从零开始建立客户名单？']},
   'S': {'name': '稳健型', 'tag': '沉着、可靠',
         'desc': '你有耐心、忠诚，乐于支持别人。大家相信你稳重，也会照顾身边的人。',
         'strengths': ['善于聆听', '可靠、始终如一', '能建立长久关系'],
         'work': '在稳定友好的团队里、期望清楚、变化有缓冲时间，你的表现最好。',
         'tips': ['即使可能引起分歧，也尽早表达你的看法。', '忙不过来时，学习温和地说“不”。', '把改变看作成长的机会，一步一步来。'],
         'also': '你也带有稳健型的一面：耐心，真心关心他人。',
         'talk': ['保持平静友善，给时间思考，避免逼他们当场决定。', '解释原因、步骤和能得到的支持。', '直接询问他们的看法；他们不一定会主动表示不同意见。'],
         'pressure': ['可能沉默、让步或回避冲突。', '可能抗拒突如其来的改变。'],
         'role': '顾问角色：客户关怀、建立信任和长期服务是强项。需留意陌生拜访、自信表达和请客户做决定。',
         'questions': ['你对接触不认识的人有什么感觉？', '说说你不得不不同意你尊敬的人的一次经历。', '计划临时改变时，你如何应对？']},
   'C': {'name': '谨慎型', 'tag': '细心、准确',
         'desc': '你重视质量、准确和良好的规划。你会想清楚再行动，也喜欢把事情做好。',
         'strengths': ['注重细节', '思路有逻辑、懂得提前规划', '坚持高标准'],
         'work': '资料清楚、有时间准备、标准明确时，你的表现最好。',
         'tips': ['信息“足够好”就可以决定，完美的信息很少存在。', '除了流程，也多与人沟通。', '早点分享你的工作，让别人来帮忙。'],
         'also': '你也带有谨慎型的一面：细心、有条理、眼光细致。',
         'talk': ['精确并做好准备；带上事实、例子和细节。', '给时间阅读和思考，避免催促。', '坦诚说明限制；他们会注意到漏洞。'],
         'pressure': ['可能变得挑剔、疏离或过度分析。', '为避免出错而拖延决定。'],
         'role': '顾问角色：产品知识、合规和规划是强项。需留意主动接触的精力、决定的速度和对新朋友的亲切感。',
         'questions': ['信息不齐全时，你怎样做决定？', '面对拒绝和与陌生人闲聊，你的舒适度如何？', '说说完美主义拖慢你的一次经历。']},
  },
  'ui': {
   'level': {'high': '高', 'mid': '中', 'low': '低'},
   'flag': {
     'fast': '完成得非常快（{n} 秒），答案可能没有经过思考。',
     'position': '大部分题目选了相同位置的答案，可能没有仔细阅读每一题。',
     'extreme': '结果非常极端，对方可能是按理想角色而不是按自己来回答。',
     'flat': '结果非常平均，对方可能犹豫不决或难以选择。'},
   'align': {
     'consistent': '职业代码（{code}）与 DISC（{style}）方向一致。',
     'partly': '部分一致：职业代码（{code}）与 DISC（{style}）只有部分重叠。请询问对方如何看待自己。',
     'differs': '两项结果不一致：兴趣（{code}）与工作风格（{style}）并不是常见的对应。这本身不是问题，可在面谈中探讨。',
     'none': '没有可对照的职业代码结果。'},
   'blend': '{a}，兼具{b}',
  },
 },
}

SETTINGS = {
 'questions': 28,
 'high': 6,            # score >= 6 -> High, <= -6 -> Low (scores run -28..28 and always sum to 0)
 'secondary_gap': 6,   # a second style is named when it is within 6 points of the first and not negative
 'min_seconds': 150,   # faster than this is flagged
 'position_share': 0.64,
 'extreme': 22,
 'flat': 3,
 'affinity': {'D': ['E', 'R'], 'I': ['E', 'S', 'A'], 'S': ['S', 'C'], 'C': ['C', 'I', 'R']},   # Holland letters that usually go with each DISC style
}

perms = list(itertools.permutations(T))
questions = [list(perms[(i * 5) % 24]) for i in range(28)]   # which type sits at each of the 4 positions, per question

words = [[W[t][i] for t in questions[i]] for i in range(28)]
js = ('/* DISC words shown to the candidate: English / Chinese per option, in display order. Nothing else is in this file. */\n'
      'const DISC_WORDS = ' + json.dumps([[{'en': e, 'zh': z} for e, z in q] for q in words], ensure_ascii=False, separators=(',', ':')) + ';\n')

os.makedirs(os.path.join(ROOT, 'private/data'), exist_ok=True)
with open(os.path.join(ROOT, 'private/data/disc.json'), 'w', encoding='utf-8') as f:
    json.dump({'questions': questions, 'settings': SETTINGS}, f, ensure_ascii=False, indent=1)
with open(os.path.join(ROOT, 'private/data/disc_text.json'), 'w', encoding='utf-8') as f:
    json.dump(TEXT, f, ensure_ascii=False, indent=1)
with open(os.path.join(ROOT, 'public_html/career-code/assets/disc-words.js'), 'w', encoding='utf-8') as f:
    f.write(js)
print('ok', len(questions), 'questions')
