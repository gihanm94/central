import { File } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

import { deleteQuietly } from './files';

/** The server takes photos up to 2 MB; stay a little below. */
export const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
const TARGET_BYTES = MAX_PHOTO_BYTES - 64 * 1024;

/** Tried in order until the JPEG is small enough. */
export const COMPRESSION_STEPS = [
  { width: 1600, quality: 0.7 },
  { width: 1280, quality: 0.6 },
  { width: 1024, quality: 0.5 },
  { width: 800, quality: 0.4 },
] as const;

export interface PreparedPhoto {
  uri: string;
  name: string;
  type: 'image/jpeg';
  size: number;
}

export interface PhotoDeps {
  /** Resize to `width` (if wider) and save as JPEG; returns the new file's uri. */
  render(uri: string, width: number, quality: number, sourceWidth: number | undefined): Promise<string>;
  size(uri: string): number;
  remove(uri: string): void;
}

const nativeDeps: PhotoDeps = {
  async render(uri, width, quality, sourceWidth) {
    const ctx = ImageManipulator.manipulate(uri);
    if (!sourceWidth || sourceWidth > width) ctx.resize({ width });
    const image = await ctx.renderAsync();
    const saved = await image.saveAsync({ compress: quality, format: SaveFormat.JPEG });
    return saved.uri;
  },
  size: (uri) => new File(uri).size ?? 0,
  remove: deleteQuietly,
};

export class PhotoTooLargeError extends Error {
  constructor() {
    super('photo_too_large');
    this.name = 'PhotoTooLargeError';
  }
}

/** Camera photo → JPEG under 2 MB for POST /machines/m/{code}/check. */
export async function preparePhoto(uri: string, sourceWidth?: number, deps: PhotoDeps = nativeDeps): Promise<PreparedPhoto> {
  for (const step of COMPRESSION_STEPS) {
    const out = await deps.render(uri, step.width, step.quality, sourceWidth);
    const size = deps.size(out);
    if (size > 0 && size <= TARGET_BYTES) {
      return { uri: out, name: `check-${Date.now()}.jpg`, type: 'image/jpeg', size };
    }
    deps.remove(out);
  }
  throw new PhotoTooLargeError();
}
