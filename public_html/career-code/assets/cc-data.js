/* Career Code questionnaire + candidate-facing text. Public by design: contains NO advisor logic. */
const TYPES = ["R","I","A","S","E","C"];
const STR = {
en:{
  docTitle:"Career Code – Holland Interest Profile", brand:"Career Code", langBtn:"中文",
  wTitle:"Discover your career code",
  wP1:"This questionnaire maps your interests onto six work personality types. Your top three types form your Holland code, which shows the kind of work you're likely to enjoy and where your strengths lie.",
  wP2:"Takes about 20 to 25 minutes. There are no right or wrong answers, so answer for yourself rather than for any particular job.",
  start:"Get started", back:"Back", continue:"Continue",
  dTitle:"About you", dReq:"Fields marked * are required.",
  fName:"Full name *", fMobile:"Mobile number *", fEmail:"Email *", fAge:"Age group", fEdu:"Highest qualification", fStatus:"Current status", fField:"Current or last job / field of study", select:"Select",
  opts:{
    age:["Below 21","21 to 25","26 to 30","31 to 35","36 to 40","41 to 50","Above 50"],
    edu:["SPM or equivalent","STPM / Matriculation / Foundation / A-Level","Certificate (SKM / Sijil)","Diploma","Bachelor's degree","Master's / PhD","Other"],
    status:["Studying","Working full-time","Working part-time","Between jobs","Other"]
  },
  consent:a=>`I agree to ${a} collecting and using my personal data to assess my career interests, to contact me about career opportunities, and to share my results with my advisor and their upline leaders. I understand I may ask to access, correct or delete my data by emailing hello@dreamhub-e3.com (Personal Data Protection Act 2010).`,
  eName:"Enter your full name.", eEmail:"Enter a valid email address - your result is sent there.", eMobile:"Enter a valid Malaysian mobile number, e.g. 012-345 6789.", eConsent:"Tick the consent box to continue.",
  cTitle:"How would you like to continue?", cTest:"Take the test", cTestP:"Answer four short parts. Your code is calculated at the end.",
  cManual:"I already have my results", cManualP:"Key in the Part A to D scores and totals from a test done earlier.",
  step:{ "s-details":"Your details","s-choose":"Choose","s-manual":"Enter results","s-results":"Results" },
  stepTest:n=>`Part ${n} of 4`,
  parts:[
    {title:"Part A: Activities", intro:"Tap every activity you would like to do. Leave the rest blank."},
    {title:"Part B: Skills", intro:"Tap every activity you can do well or competently."},
    {title:"Part C: Jobs", intro:"Tap every job that interests or appeals to you, even if you have no training for it."},
    {title:"Part D: Rate yourself", intro:"Compare yourself with other people your age. 1 is low, 7 is high. Within each set, each number can be used only once, so think about where you are strongest and weakest."}
  ],
  set:i=>`Set ${i} of 6`, previous:"Previous", next:"Next", seeMine:"See my results",
  selected:n=>`${n} selected`, rated:n=>`${n} of 12 rated`, eRate:"Rate all 12 items before seeing your results.",
  low:"Low", avg:"Average", high:"High", rateSets:["Set 1: Abilities","Set 2: Skills"], eDup:"Each number can be used only once within a set.",
  mTitle:"Enter existing results", mIntro:"Key in each part score. The total fills in automatically when Parts A to D are entered; you can also type a total directly if only totals are available.",
  type:"Type", part:k=>`Part ${k}`, total:"Total", max:n=>`max ${n}`,
  mWarn:(s,t)=>`Parts add up to ${s}, but the total says ${t}. The total you entered will be used.`,
  mErr:l=>`Enter a valid total for: ${l}.`, seeResults:"See results",
  rCode:"Your career code",
  summary:(a,b,c)=>`Your strongest interest is <b>${a}</b>, followed by <b>${b}</b> and <b>${c}</b>.`,
  tie:(a,b,code)=>` Your third letter is tied between ${a} and ${b}, so ${code} describes you equally well.`,
  rOrient:"Your work orientation", people:"People-focused", task:"Task-focused", data:"Structure and data", ideas:"Ideas and imagination",
  leanClear:x=>`You lean clearly towards ${x}.`, leanSlight:x=>`You lean slightly towards ${x}.`, balanced:"You are balanced between the two.",
  pPeople:"working with and through people", pTask:"practical tasks, tools and systems", pData:"clear structure, facts and organised information", pIdeas:"ideas, concepts and creative thinking",
  rStrengths:"Your career strengths", rFields:"Fields that often suit your code", rScores:"Your scores",
  printCand:"Print candidate summary", editScores:"Edit scores", newCand:"New candidate",
  confirmNew:"Clear this candidate's details and answers and start again?",
  phTitle:n=>`Career Code profile: ${n}`, phAge:a=>`Age ${a}`, phKeyed:", scores keyed in from an earlier test",
  fileName:n=>`Career Code - ${n}`, candidate:"candidate",
  dateLocale:"en-MY", hexLabel:"Holland hexagon chart"
},
zh:{
  docTitle:"职业代码 – 霍兰德职业兴趣测评", brand:"职业代码", langBtn:"EN",
  wTitle:"发现你的职业代码",
  wP1:"这份问卷会把你的兴趣对应到六种职业性格类型。得分最高的三种类型组成你的霍兰德代码，显示你可能喜欢的工作类型以及你的优势所在。",
  wP2:"大约需要20至25分钟。答案没有对错之分，请按照自己的真实想法作答，而不是为了某个职位而作答。",
  start:"开始", back:"返回", continue:"继续",
  dTitle:"个人资料", dReq:"标有 * 的栏位为必填。",
  fName:"姓名 *", fMobile:"手机号码 *", fEmail:"电邮 *", fAge:"年龄组", fEdu:"最高学历", fStatus:"目前状况", fField:"目前或最近的工作／修读科系", select:"请选择",
  opts:{
    age:["21岁以下","21至25岁","26至30岁","31至35岁","36至40岁","41至50岁","50岁以上"],
    edu:["SPM／同等学历","STPM／预科／基础班／A水准","技能证书（SKM／证书）","文凭","学士学位","硕士／博士","其他"],
    status:["在学","全职工作","兼职工作","待业中","其他"]
  },
  consent:a=>`我同意${a}收集和使用我的个人资料，以评估我的职业兴趣、就职业机会与我联系，并将我的结果分享给我的顾问及其上线领导。我明白我可以电邮至 hello@dreamhub-e3.com，要求查阅、更正或删除我的资料（《2010年个人资料保护法令》）。`,
  eName:"请输入姓名。", eEmail:"请输入有效的电邮地址，结果将发送到该地址。", eMobile:"请输入有效的马来西亚手机号码，例如 012-345 6789。", eConsent:"请勾选同意栏位以继续。",
  cTitle:"你想怎样继续？", cTest:"进行测试", cTestP:"回答四个简短部分，系统会在最后计算你的代码。",
  cManual:"我已有测试结果", cManualP:"输入之前测试的A至D部分分数及总分。",
  step:{ "s-details":"个人资料","s-choose":"选择","s-manual":"输入结果","s-results":"结果" },
  stepTest:n=>`第${n}部分，共4部分`,
  parts:[
    {title:"A部分：活动", intro:"点选所有你想做的活动，其余的留空即可。"},
    {title:"B部分：能力", intro:"点选所有你能做得好或能胜任的事情。"},
    {title:"C部分：职业", intro:"点选所有你感兴趣或吸引你的职业，即使你没有相关训练也可以。"},
    {title:"D部分：自我评分", intro:"与同龄人相比，为自己评分。1为最低，7为最高。每一组中，每个数字只能使用一次，请想一想自己最强和最弱的是哪一项。"}
  ],
  set:i=>`第${i}组（共6组）`, previous:"上一步", next:"下一步", seeMine:"查看我的结果",
  selected:n=>`已选${n}项`, rated:n=>`已评${n}项（共12项）`, eRate:"请先为全部12项评分，才能查看结果。",
  low:"低", avg:"一般", high:"高", rateSets:["第1组：能力","第2组：技能"], eDup:"同一组中，每个数字只能使用一次。",
  mTitle:"输入已有结果", mIntro:"请输入每个部分的分数。填好A至D部分后，总分会自动计算；如果只有总分，也可以直接输入总分。",
  type:"类型", part:k=>`${k}部分`, total:"总分", max:n=>`最高${n}`,
  mWarn:(s,t)=>`各部分相加为${s}，但总分为${t}。系统将采用你输入的总分。`,
  mErr:l=>`请为以下类型输入有效总分：${l}。`, seeResults:"查看结果",
  rCode:"你的职业代码",
  summary:(a,b,c)=>`你最强的兴趣是<b>${a}</b>，其次是<b>${b}</b>和<b>${c}</b>。`,
  tie:(a,b,code)=>`你的第三个字母由${a}和${b}并列，因此${code}同样适合描述你。`,
  rOrient:"你的工作取向", people:"以人为本", task:"以任务为本", data:"结构与数据", ideas:"创意与想象",
  leanClear:x=>`你明显偏向${x}。`, leanSlight:x=>`你略为偏向${x}。`, balanced:"你在两者之间较为平衡。",
  pPeople:"与人合作、通过人来完成工作", pTask:"实际任务、工具和系统", pData:"清晰的结构、事实和有条理的资料", pIdeas:"想法、概念和创意思维",
  rStrengths:"你的职业优势", rFields:"适合你代码的领域", rScores:"你的分数",
  printCand:"打印候选人摘要", editScores:"修改分数", newCand:"新候选人",
  confirmNew:"要清除此候选人的资料和答案并重新开始吗？",
  phTitle:n=>`职业代码报告：${n}`, phAge:a=>`年龄：${a}`, phKeyed:"，分数取自之前的测试",
  fileName:n=>`职业代码 - ${n}`, candidate:"候选人",
  dateLocale:"zh-MY", hexLabel:"霍兰德六边形图"
}};
const INFO = {
en:{
  R:{name:"Realistic", tag:"The Doer", desc:"Practical and hands-on. You like working with tools, machines, the outdoors or anything you can see and touch, and you prefer clear, tangible results.", strengths:["Practical problem-solving","Hands-on skills","Getting things done","Dependability"], fields:["Engineering","Technical trades","Logistics","Outdoor and physical work"]},
  I:{name:"Investigative", tag:"The Thinker", desc:"Curious and analytical. You enjoy understanding how things work, solving complex problems and making decisions based on evidence.", strengths:["Analytical thinking","Research","Complex problem-solving","Fast learning"], fields:["Science and research","Data and technology","Healthcare","Finance and analysis"]},
  A:{name:"Artistic", tag:"The Creator", desc:"Imaginative and expressive. You value originality and enjoy work where you can create, design or communicate ideas in your own way.", strengths:["Creativity","Original ideas","Visual and written expression","Adaptability"], fields:["Design","Media and content","Marketing and branding","The arts"]},
  S:{name:"Social", tag:"The Helper", desc:"Warm and people-focused. You enjoy helping, teaching and supporting others, and you are good at building trust and understanding what people need.", strengths:["Building relationships","Listening","Empathy","Teamwork"], fields:["Education and training","Healthcare and wellness","Advisory and counselling","Community services"]},
  E:{name:"Enterprising", tag:"The Persuader", desc:"Energetic and ambitious. You enjoy leading, influencing and persuading others, and you are motivated by goals, rewards and new opportunities.", strengths:["Persuasion","Leadership","Initiative","Resilience"], fields:["Sales and business development","Entrepreneurship","Management","Financial services"]},
  C:{name:"Conventional", tag:"The Organiser", desc:"Organised and dependable. You like clear procedures, accuracy and order, and you take pride in doing things properly and on time.", strengths:["Organisation","Attention to detail","Reliability","Working with numbers and data"], fields:["Finance and accounting","Administration","Operations","Compliance and risk"]}
},
zh:{
  R:{name:"实际型", tag:"实干者", desc:"务实、喜欢动手。你喜欢使用工具、机器，或在户外工作，喜欢看得见、摸得着的事物，并偏好清晰具体的成果。", strengths:["实际解决问题","动手能力","执行力","可靠"], fields:["工程","技术行业","物流","户外及体力工作"]},
  I:{name:"研究型", tag:"思考者", desc:"好奇、善于分析。你喜欢了解事物如何运作、解决复杂问题，并根据证据做决定。", strengths:["分析思维","研究能力","解决复杂问题","学习能力强"], fields:["科学与研究","数据与科技","医疗保健","金融与分析"]},
  A:{name:"艺术型", tag:"创作者", desc:"富有想象力、善于表达。你重视原创性，喜欢能以自己的方式创作、设计或传达想法的工作。", strengths:["创造力","原创想法","视觉与文字表达","适应力"], fields:["设计","媒体与内容创作","市场营销与品牌","艺术"]},
  S:{name:"社会型", tag:"助人者", desc:"热情、以人为本。你喜欢帮助、教导和支持他人，善于建立信任并了解别人的需要。", strengths:["建立关系","倾听","同理心","团队合作"], fields:["教育与培训","医疗与保健","咨询与辅导","社区服务"]},
  E:{name:"企业型", tag:"说服者", desc:"精力充沛、有抱负。你喜欢领导、影响和说服他人，并以目标、回报和新机会为动力。", strengths:["说服力","领导力","主动性","抗压能力"], fields:["销售与业务拓展","创业","管理","金融服务"]},
  C:{name:"常规型", tag:"组织者", desc:"有条理、可靠。你喜欢清晰的程序、准确和秩序，并以把事情做好、准时完成为荣。", strengths:["组织能力","注重细节","可靠","处理数字与数据"], fields:["金融与会计","行政","营运","合规与风险管理"]}
}};
const PART_KEYS = ["A","B","C","D"];
const ITEMS = {
en:{
  A:{
    R:["Fix a leaking tap or do a small home repair","Assemble furniture from instructions","Work on a car or motorbike engine","Use power tools for a woodworking project","Grow vegetables or look after a garden","Go hiking, camping or on an outdoor expedition","Repair a broken electrical appliance","Build a model or something with my hands","Operate machinery or heavy equipment","Set up and wire home electronics","Take a course in carpentry, welding or mechanics"],
    I:["Read about new scientific discoveries","Solve puzzles, riddles or brain teasers","Run an experiment to test an idea","Analyse data to find patterns","Learn how the human body works","Study how computers or software work","Investigate what caused a problem","Read about astronomy or space","Work through a challenging maths problem","Research a topic in depth before deciding","Watch documentaries on science or nature"],
    A:["Sketch, draw or paint","Write stories, poems or a blog","Play a musical instrument or sing","Act in a play or short film","Take creative photos or videos","Design a poster, logo or web page","Decorate or style a room","Make crafts, pottery or jewellery","Dance or choreograph a routine","Visit art exhibitions or concerts","Come up with original ideas for a project"],
    S:["Help a friend through a personal problem","Teach or tutor someone","Volunteer for a community or charity cause","Organise a gathering for family or friends","Mentor a younger person","Listen to people and give advice","Care for elderly people or children","Work with a team on a group project","Meet new people at events","Lead a support or interest group","Help a newcomer settle in"],
    E:["Lead a team or committee","Sell a product or idea to someone","Start a small business or side hustle","Persuade others to see my point of view","Negotiate a deal or a price","Organise and promote an event","Speak in front of a group","Set targets and push to hit them","Network with business people","Run for a leadership position","Take charge when a group is stuck"],
    C:["Keep my personal spending within a budget","Organise files, documents or records","Enter data accurately into a spreadsheet","Follow a clear procedure step by step","Plan a detailed schedule or itinerary","Check documents for errors","Keep track of stock or inventory","Fill in forms carefully and completely","Sort and label things so they are easy to find","Prepare a report using numbers","Work in a neat, orderly office"]
  },
  B:{
    R:["Use hand tools such as a hammer, screwdriver or spanner","Read a technical drawing or diagram","Change a car tyre","Fix simple plumbing or electrical faults","Operate a drill or saw safely","Assemble equipment from instructions","Do physically demanding work for long hours","Maintain a vehicle or bicycle","Set up a computer or home network","Cook for a large group","Find my way using a map or compass"],
    I:["Use a scientific calculator","Understand a research article or technical report","Explain how a scientific process works","Use statistics to analyse results","Write simple computer code or spreadsheet formulas","Identify the root cause of a problem","Interpret charts and graphs","Do mental arithmetic quickly","Design a simple experiment","Judge whether evidence supports a claim","Learn technical subjects quickly"],
    A:["Draw people or objects realistically","Write creatively in my own voice","Play a musical instrument","Design things that look attractive","Perform in front of an audience","Use design or editing software such as Canva or Photoshop","Come up with unusual solutions","Express ideas through pictures or stories","Combine colours and layouts well","Sing or dance well","Make handmade crafts"],
    S:["Explain things so others understand easily","Make people feel at ease","Listen without judging","Help settle a disagreement between people","Work well in a team","Teach a skill to others","Remember names and personal details","Sense how someone is feeling","Encourage and motivate others","Build rapport with strangers quickly","Keep in touch with a wide circle of people"],
    E:["Persuade people to agree with me","Lead a group towards a goal","Win over someone who was unsure","Speak confidently in public","Make decisions under pressure","Handle rejection and keep going","Negotiate a good outcome","Start conversations with strangers","Get others to take action","Set goals and achieve them","Spot business opportunities"],
    C:["Type quickly and accurately","Keep accurate records","Use spreadsheets such as Excel confidently","Manage a budget or petty cash","Follow detailed rules and regulations","Spot errors in numbers or documents","Organise a busy schedule","File and find documents efficiently","Complete paperwork on time","Use accounting or office software","Stay accurate during repetitive tasks"]
  },
  C:{
    R:["Electrician","Aircraft maintenance engineer","Automotive mechanic","Carpenter","Chef","Plumber","Air-con technician","Construction site supervisor","Marine engineer","Agritech farmer","Machine operator","Lift technician","Park ranger","Land surveyor"],
    I:["Doctor","Pharmacist","Research scientist","Data scientist","Software engineer","Actuary","Economist","Biomedical scientist","Laboratory technician","Veterinarian","Chemist","Cybersecurity analyst","Environmental scientist","Mathematician"],
    A:["Graphic designer","Interior designer","Author","Photographer","Musician","Actor","Film director","Fashion designer","Architect","Animator","Copywriter","Content creator","Illustrator","Choreographer"],
    S:["Teacher","Nurse","Social worker","Counsellor","Physiotherapist","Career coach","Youth worker","Preschool educator","Occupational therapist","Speech therapist","Training facilitator","Community development officer","Eldercare coordinator","Customer care specialist"],
    E:["Sales manager","Entrepreneur","Real estate agent","Marketing manager","Lawyer","Business development manager","Restaurant owner","Event manager","Public relations manager","Stockbroker","Politician","Hotel manager","Recruitment consultant","Retail store manager"],
    C:["Accountant","Bank operations officer","Administrative executive","Auditor","Payroll officer","Bookkeeper","Data entry specialist","Logistics coordinator","Tax officer","Medical records officer","Compliance officer","Office manager","Procurement officer","Legal secretary"]
  }
},
zh:{
  A:{
    R:["修理漏水的水龙头或做简单的家居维修","按照说明组装家具","修理汽车或摩托车引擎","用电动工具做木工","种菜或照料花园","去远足、露营或户外探险","修理坏掉的电器","亲手制作模型或物品","操作机器或重型设备","安装并连接家用电子设备","修读木工、焊接或机械课程"],
    I:["阅读新的科学发现","解谜题、猜谜或玩脑筋急转弯","做实验来验证一个想法","分析数据以找出规律","了解人体如何运作","研究电脑或软件如何运作","调查问题发生的原因","阅读天文或太空的资料","解答具挑战性的数学题","做决定前深入研究一个课题","观看科学或自然纪录片"],
    A:["素描、绘画或上色","写故事、诗或博客","弹奏乐器或唱歌","参演话剧或短片","拍摄有创意的照片或视频","设计海报、标志或网页","布置或装饰房间","制作手工艺品、陶艺或首饰","跳舞或编排舞蹈","参观艺术展览或音乐会","为项目构思原创点子"],
    S:["帮助朋友解决个人问题","教导或辅导他人","为社区或慈善活动当义工","为家人或朋友筹办聚会","指导年轻人","聆听他人并给予建议","照顾长者或小孩","与团队一起完成小组项目","在活动中认识新朋友","带领互助小组或兴趣小组","帮助新来的人适应环境"],
    E:["领导团队或委员会","向别人推销产品或想法","开小生意或做副业","说服别人接受我的观点","谈判交易或价格","筹办并宣传活动","在一群人面前讲话","设定目标并努力达成","与商界人士建立人脉","竞选领导职位","在团队陷入僵局时主导局面"],
    C:["把个人开支控制在预算内","整理文件、资料或记录","准确地把数据输入电子表格","按部就班地遵循清晰的程序","规划详细的时间表或行程","检查文件中的错误","记录库存或存货","仔细完整地填写表格","把物品分类并贴上标签，方便查找","用数字准备报告","在整洁有序的办公室工作"]
  },
  B:{
    R:["使用锤子、螺丝刀或扳手等手工具","看懂技术图纸或图表","更换汽车轮胎","修理简单的水管或电路问题","安全地操作电钻或电锯","按照说明组装设备","长时间从事体力劳动","保养汽车或脚车","设置电脑或家庭网络","为一大群人煮食","用地图或指南针辨认方向"],
    I:["使用科学计算器","看懂研究文章或技术报告","解释科学过程如何运作","用统计方法分析结果","编写简单的程序或电子表格公式","找出问题的根本原因","解读图表","快速心算","设计简单的实验","判断证据是否支持某个说法","快速掌握技术性科目"],
    A:["逼真地画出人物或物件","用自己的风格进行创意写作","弹奏乐器","设计出好看的东西","在观众面前表演","使用Canva或Photoshop等设计或剪辑软件","想出与众不同的解决方法","通过图像或故事表达想法","善于搭配颜色和排版","唱歌或跳舞跳得好","制作手工艺品"],
    S:["把事情解释得让人容易明白","让人感到自在","不带批判地聆听","帮忙化解他人之间的分歧","在团队中合作愉快","教导他人一项技能","记住别人的名字和个人细节","察觉别人的感受","鼓励和激励他人","很快与陌生人建立融洽关系","与广泛的人脉保持联系"],
    E:["说服别人同意我的看法","带领团队达成目标","说服原本犹豫不决的人","自信地公开演讲","在压力下做决定","面对拒绝后继续努力","谈判出好的结果","主动与陌生人攀谈","推动他人采取行动","设定目标并实现","发掘商机"],
    C:["打字又快又准确","保持准确的记录","熟练使用Excel等电子表格","管理预算或零用现金","遵守详细的规则和条例","发现数字或文件中的错误","安排繁忙的日程","有效率地存档和查找文件","准时完成文书工作","使用会计或办公软件","在重复性工作中保持准确"]
  },
  C:{
    R:["电工","飞机维修工程师","汽车技工","木匠","厨师","水喉工","冷气技师","建筑工地主管","船舶工程师","农业科技农夫","机器操作员","电梯技师","公园管理员","土地测量师"],
    I:["医生","药剂师","研究科学家","数据科学家","软件工程师","精算师","经济学家","生物医学科学家","实验室技术员","兽医","化学家","网络安全分析师","环境科学家","数学家"],
    A:["平面设计师","室内设计师","作家","摄影师","音乐人","演员","电影导演","时装设计师","建筑师","动画师","文案撰稿人","内容创作者","插画师","编舞师"],
    S:["教师","护士","社工","辅导员","物理治疗师","职业教练","青少年工作者","学前教育老师","职能治疗师","言语治疗师","培训导师","社区发展专员","乐龄护理协调员","客户关怀专员"],
    E:["销售经理","企业家","房地产经纪","市场营销经理","律师","业务拓展经理","餐馆老板","活动经理","公关经理","股票经纪","政治人物","酒店经理","招聘顾问","零售店经理"],
    C:["会计师","银行营运专员","行政专员","审计师","薪资专员","簿记员","数据输入专员","物流协调员","税务官员","医疗记录专员","合规专员","办公室经理","采购专员","法律秘书"]
  }
}};
const RATINGS = {
  en:{R:["Mechanical ability","Hands-on practical skills"],I:["Scientific ability","Problem-solving skills"],A:["Artistic ability","Creative expression"],S:["Teaching ability","People skills"],E:["Sales ability","Leadership skills"],C:["Clerical ability","Office and organising skills"]},
  zh:{R:["机械能力","动手实践能力"],I:["科学能力","解决问题能力"],A:["艺术能力","创意表达能力"],S:["教导能力","人际能力"],E:["销售能力","领导能力"],C:["文书能力","办公及组织能力"]}
};
const RATE_ORDER = [["R",0],["S",0],["I",0],["E",0],["A",0],["C",0],["R",1],["S",1],["I",1],["E",1],["A",1],["C",1]];
