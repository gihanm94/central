import { COMPRESSION_STEPS, MAX_PHOTO_BYTES, PhotoTooLargeError, preparePhoto, type PhotoDeps } from '../photo';

jest.mock('expo-image-manipulator', () => ({ ImageManipulator: {}, SaveFormat: { JPEG: 'jpeg' } }));

function deps(sizes: number[]) {
  const removed: string[] = [];
  const renders: { width: number; quality: number }[] = [];
  const d: PhotoDeps = {
    render: async (_uri, width, quality) => {
      renders.push({ width, quality });
      return `file:///out-${renders.length}.jpg`;
    },
    size: (uri) => sizes[Number(/out-(\d+)/.exec(uri)![1]) - 1]!,
    remove: (uri) => removed.push(uri),
  };
  return { d, removed, renders };
}

describe('preparePhoto', () => {
  it('keeps the first result under 2 MB', async () => {
    const { d, renders } = deps([800_000]);
    const p = await preparePhoto('file:///raw.jpg', 4000, d);
    expect(p).toMatchObject({ uri: 'file:///out-1.jpg', type: 'image/jpeg', size: 800_000 });
    expect(p.name).toMatch(/\.jpg$/);
    expect(renders).toEqual([COMPRESSION_STEPS[0]]);
  });

  it('compresses harder until it fits, deleting the too-large tries', async () => {
    const { d, removed, renders } = deps([3_000_000, MAX_PHOTO_BYTES, 1_500_000]);
    const p = await preparePhoto('file:///raw.jpg', 4000, d);
    expect(p.uri).toBe('file:///out-3.jpg');
    expect(renders).toHaveLength(3);
    expect(removed).toEqual(['file:///out-1.jpg', 'file:///out-2.jpg']);
  });

  it('gives up with PhotoTooLargeError', async () => {
    const { d } = deps(COMPRESSION_STEPS.map(() => 5_000_000));
    await expect(preparePhoto('file:///raw.jpg', 4000, d)).rejects.toBeInstanceOf(PhotoTooLargeError);
  });
});
