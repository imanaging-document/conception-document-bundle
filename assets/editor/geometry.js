export const mm = (value) => {
  const n = parseFloat(value);
  if (!Number.isFinite(n)) return 0;
  return /px$/.test(value)
    ? (n * 25.4) / 96
    : /pt$/.test(value)
      ? (n * 25.4) / 72
      : n;
};
export function align(boxes, command, target) {
  if (!boxes.length) return [];
  const ref = target || boxes.at(-1);
  const out = boxes.map((b) => ({ ...b }));
  if (command === "distribute-x" || command === "distribute-y") {
    if (boxes.length < 3) return out;
    const axis = command.endsWith("x") ? "x" : "y",
      size = axis === "x" ? "w" : "h";
    out.sort((a, b) => a[axis] - b[axis]);
    const start = out[0][axis],
      end = out.at(-1)[axis] + out.at(-1)[size];
    const gap =
      (end - start - out.reduce((v, b) => v + b[size], 0)) / (out.length - 1);
    let position = start;
    for (const box of out) {
      box[axis] = position;
      position += box[size] + gap;
    }
    return out;
  }
  for (const box of out) {
    if (command === "left") box.x = ref.x;
    if (command === "center") box.x = ref.x + (ref.w - box.w) / 2;
    if (command === "right") box.x = ref.x + ref.w - box.w;
    if (command === "top") box.y = ref.y;
    if (command === "middle") box.y = ref.y + (ref.h - box.h) / 2;
    if (command === "bottom") box.y = ref.y + ref.h - box.h;
  }
  return out;
}
export function snapBox(box, others, threshold) {
  let dx = 0,
    dy = 0,
    bestX = threshold,
    bestY = threshold,
    guides = [];
  for (const other of others) {
    for (const a of [0, 0.5, 1])
      for (const b of [0, 0.5, 1]) {
        const x = other.x + other.w * b - (box.x + box.w * a),
          y = other.y + other.h * b - (box.y + box.h * a);
        if (Math.abs(x) < bestX) {
          bestX = Math.abs(x);
          dx = x;
          guides = guides.filter((g) => g.axis !== "x");
          guides.push({ axis: "x", value: other.x + other.w * b });
        }
        if (Math.abs(y) < bestY) {
          bestY = Math.abs(y);
          dy = y;
          guides = guides.filter((g) => g.axis !== "y");
          guides.push({ axis: "y", value: other.y + other.h * b });
        }
      }
  }
  // Equal gaps in a row/column, including extending an existing rhythm.
  for (const axis of ["x", "y"]) {
    const size = axis === "x" ? "w" : "h",
      cross = axis === "x" ? "y" : "x",
      crossSize = axis === "x" ? "h" : "w";
    const candidates = others
      .filter(
        (o) =>
          o[cross] < box[cross] + box[crossSize] &&
          o[cross] + o[crossSize] > box[cross],
      )
      .sort((a, b) => a[axis] - b[axis]);
    for (let i = 0; i < candidates.length - 1; i++) {
      const left = candidates[i],
        right = candidates[i + 1],
        gap = right[axis] - left[axis] - left[size];
      if (gap < 0) continue;
      const positions = [
        left[axis] + left[size] + (gap - box[size]) / 2,
        right[axis] + right[size] + gap,
        left[axis] - gap - box[size],
      ];
      for (const value of positions) {
        const delta = value - box[axis],
          best = axis === "x" ? bestX : bestY;
        if (Math.abs(delta) < best) {
          if (axis === "x") {
            dx = delta;
            bestX = Math.abs(delta);
          } else {
            dy = delta;
            bestY = Math.abs(delta);
          }
          guides = guides.filter((g) => g.axis !== axis);
          guides.push({ axis, value, spacing: true });
        }
      }
    }
  }
  return { x: box.x + dx, y: box.y + dy, guides };
}
