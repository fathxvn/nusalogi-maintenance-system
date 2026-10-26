export function distributionPercentages(counts: number[]): number[] {
    const total = counts.reduce((sum, count) => sum + count, 0);
    if (!total) return counts.map(() => 0);

    const exact = counts.map((count) => (count / total) * 1000);
    const tenths = exact.map(Math.floor);
    const remaining = 1000 - tenths.reduce((sum, value) => sum + value, 0);
    const order = exact
        .map((value, index) => ({ index, remainder: value - tenths[index] }))
        .sort((a, b) => b.remainder - a.remainder);

    for (let index = 0; index < remaining; index++) {
        tenths[order[index].index] += 1;
    }

    return tenths.map((value) => value / 10);
}
