// 포털 소개 한 장 슬라이드: 가운데 메인 화면 + 좌/우/하단에 기능 화면 캡처 배치
const path = require("path");
const pptxgen = require("pptxgenjs");
const { applyTheme } = require(process.env.APPLY_THEME);

const IMG = (n) => path.join(__dirname, "placeholders", `${n}.png`);
const OUT = path.join(__dirname, "portal_intro.pptx");

const THEME = {
  name: "Portal Intro",
  headFontFace: "맑은 고딕",
  bodyFontFace: "맑은 고딕",
  colors: {
    dk1: "0F1B2D", lt1: "FFFFFF", dk2: "1C2B42", lt2: "E8EEF6",
    accent1: "2EC4B6", accent2: "F6C177", accent3: "7FB8E6",
    accent4: "9FB7D6", accent5: "5B6B82", accent6: "E76F51",
    hlink: "2EC4B6", folHlink: "9FB7D6",
  },
};

// ── 편집 포인트: 포털 이름 / 문구 / 기능 목록 ──
const PORTAL = "OOO 통합 포털";
const TAGLINE = "흩어진 업무를 하나의 포털에서 — 검색·소통·결재·분석까지";
const URL = "portal.company.com";
const MAIN = { img: "main", title: "메인 대시보드", desc: "오늘의 업무 현황을 한 화면에" };
const FEATURES = {
  left:   [{ img: "search", title: "통합 검색", desc: "모든 자료 한 번에" },
           { img: "board", title: "공지·게시판", desc: "전사 소식 공유" }],
  right:  [{ img: "calendar", title: "일정 관리", desc: "개인·팀 일정 통합" },
           { img: "notice", title: "알림·메신저", desc: "실시간 업무 소통" }],
  bottom: [{ img: "approval", title: "전자결재", desc: "기안부터 승인까지" },
           { img: "report", title: "데이터 리포트", desc: "핵심 지표 시각화" },
           { img: "mypage", title: "마이페이지", desc: "내 정보·권한 관리" },
           { img: "mobile", title: "모바일 지원", desc: "언제 어디서나 접속" }],
};

(async () => {
  const pres = new pptxgen();
  pres.layout = "LAYOUT_WIDE"; // 13.333 x 7.5
  pres.theme = { headFontFace: THEME.headFontFace, bodyFontFace: THEME.bodyFontFace };
  pres.title = `${PORTAL} 소개`;
  const C = pres.SchemeColor;

  pres.defineSlideMaster({
    title: "Portal Overview",
    background: { color: C.text1 },
    objects: [
      { placeholder: { options: { name: "title", type: "title", x: 0.5, y: 0.62, w: 6.5, h: 0.62,
          fontSize: 30, bold: true, color: C.background1, margin: 0, align: "left", valign: "middle" }, text: "" } },
    ],
  });
  pres.addSection({ title: "포털 소개" });
  const s = pres.addSlide({ masterName: "Portal Overview", sectionTitle: "포털 소개" });

  // 헤더
  s.addText("PORTAL OVERVIEW", { x: 0.5, y: 0.3, w: 4, h: 0.3, fontSize: 11, bold: true,
    color: C.accent1, charSpacing: 4, margin: 0, isTextBox: true });
  s.addText(PORTAL, { placeholder: "title" });
  s.addText([
    { text: TAGLINE, options: { fontSize: 14, color: C.background2, breakLine: true } },
    { text: URL, options: { fontSize: 11, color: C.accent1 } },
  ], { x: 6.9, y: 0.4, w: 5.933, h: 0.84, align: "right", valign: "bottom", margin: 0, isTextBox: true });

  // 그리드
  const M = 0.5, GAP = 0.3, CW = 2.858, CH = 1.608;
  const TOP = 1.5, BOT = TOP + 2 * CH + GAP + GAP;      // 하단 행 y
  const MX = M + CW + GAP, MW = 13.333 - 2 * MX, MH = 2 * CH + GAP;
  let n = 0;

  function badgeLabel(x, y, w, f, num) {
    s.addShape(pres.shapes.RECTANGLE, { x, y, w, h: 0.42, fill: { color: C.text1, transparency: 18 },
      line: { type: "none" }, objectName: `label-bg-${num}` });
    s.addShape(pres.shapes.OVAL, { x: x + 0.1, y: y + 0.07, w: 0.28, h: 0.28,
      fill: { color: C.accent1 }, line: { type: "none" }, objectName: `badge-${num}` });
    s.addText(String(num), { x: x + 0.1, y: y + 0.07, w: 0.28, h: 0.28, fontSize: 11, bold: true,
      color: C.text1, align: "center", valign: "middle", margin: 0, isTextBox: true });
    s.addText([
      { text: f.title + "  ", options: { bold: true, color: C.background1, fontSize: 12 } },
      { text: f.desc, options: { color: C.background2, fontSize: 10 } },
    ], { x: x + 0.46, y, w: w - 0.52, h: 0.42, valign: "middle", margin: 0, isTextBox: true,
      objectName: `label-${num}` });
  }

  function featureCard(x, y, f) {
    n += 1;
    s.addShape(pres.shapes.RECTANGLE, { x, y, w: CW, h: CH, fill: { color: C.background2 },
      line: { color: C.accent5, width: 0.75 },
      shadow: { type: "outer", color: "000000", opacity: 0.35, blur: 8, offset: 3, angle: 90 },
      objectName: `frame-${n}` });
    s.addImage({ path: IMG(f.img), x, y, w: CW, h: CH, altText: `${f.title} 화면 캡처`,
      objectName: `screen-${f.img}` });
    badgeLabel(x, y + CH - 0.42, CW, f, n);
  }

  // 가운데 메인 화면 (브라우저 프레임)
  const BAR = 0.24;
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: MX, y: TOP, w: MW, h: MH, rectRadius: 0.08,
    fill: { color: C.text2 }, line: { color: C.accent1, width: 1.5 },
    shadow: { type: "outer", color: "2EC4B6", opacity: 0.45, blur: 18, offset: 0, angle: 90 },
    objectName: "main-frame" });
  ["E76F51", "F6C177", "2EC4B6"].forEach((c, i) =>
    s.addShape(pres.shapes.OVAL, { x: MX + 0.14 + i * 0.16, y: TOP + 0.075, w: 0.1, h: 0.1,
      fill: { color: c }, line: { type: "none" }, objectName: `dot-${i}` }));
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: MX + MW / 2 - 1.3, y: TOP + 0.045, w: 2.6, h: 0.16,
    rectRadius: 0.08, fill: { color: C.text1 }, line: { type: "none" }, objectName: "url-bar" });
  s.addText(URL, { x: MX + MW / 2 - 1.3, y: TOP + 0.045, w: 2.6, h: 0.16, fontSize: 8,
    color: C.accent4, align: "center", valign: "middle", margin: 0, isTextBox: true });
  s.addImage({ path: IMG(MAIN.img), x: MX + 0.04, y: TOP + BAR, w: MW - 0.08, h: MH - BAR - 0.04,
    altText: `${MAIN.title} 화면 캡처`, objectName: "screen-main" });
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: MX + 0.2, y: TOP + BAR + 0.18, w: 4.3, h: 0.5,
    rectRadius: 0.25, fill: { color: C.text1, transparency: 10 }, line: { color: C.accent1, width: 1 },
    objectName: "main-label-bg" });
  s.addText([
    { text: MAIN.title + "  ", options: { bold: true, color: C.accent1, fontSize: 14 } },
    { text: MAIN.desc, options: { color: C.background1, fontSize: 11 } },
  ], { x: MX + 0.4, y: TOP + BAR + 0.18, w: 4.0, h: 0.5, valign: "middle", margin: 0, isTextBox: true,
    objectName: "main-label" });

  // 좌 · 우 · 하단 기능 화면
  FEATURES.left.forEach((f, i) => featureCard(M, TOP + i * (CH + GAP), f));
  FEATURES.right.forEach((f, i) => featureCard(13.333 - M - CW, TOP + i * (CH + GAP), f));
  FEATURES.bottom.forEach((f, i) => featureCard(M + i * (CW + (13.333 - 2 * M - 4 * CW) / 3), BOT, f));

  s.addNotes(
    "화면 교체 방법: 각 회색 와이어프레임 이미지를 우클릭 → [그림 바꾸기] → [파일에서]로 실제 캡처를 넣으면 " +
    "크기·위치가 그대로 유지됩니다. 캡처는 16:9 비율(예: 1600x900)로 저장하면 가장 깔끔합니다. " +
    "포털 이름·문구·기능명은 텍스트를 직접 클릭해 수정하세요.");

  await pres.writeFile({ fileName: OUT });
  await applyTheme(OUT, THEME);
  console.log("wrote", OUT);
})();
