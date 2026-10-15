/** Last time the person touched or typed (for the idle lock). Kept apart from AppLock to avoid import cycles. */
let lastInteraction = Date.now();

export function noteInteraction(): void {
  lastInteraction = Date.now();
}

export function msSinceInteraction(now = Date.now()): number {
  return now - lastInteraction;
}
