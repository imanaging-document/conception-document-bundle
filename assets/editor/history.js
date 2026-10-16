export class History {
  constructor() {
    this.undoStack = [];
    this.redoStack = [];
  }
  push(before, after) {
    if (JSON.stringify(before) === JSON.stringify(after)) return;
    this.undoStack.push(structuredClone(before));
    if (this.undoStack.length > 100) this.undoStack.shift();
    this.redoStack = [];
  }
  undo(current) {
    if (!this.undoStack.length) return current;
    this.redoStack.push(structuredClone(current));
    return this.undoStack.pop();
  }
  redo(current) {
    if (!this.redoStack.length) return current;
    this.undoStack.push(structuredClone(current));
    return this.redoStack.pop();
  }
}
