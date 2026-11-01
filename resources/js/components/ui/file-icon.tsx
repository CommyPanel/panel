import {
    File,
    FileArchive,
    FileAudio,
    FileCode,
    FileImage,
    FileJson,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Folder,
    FolderUp,
    Link as LinkIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

const BY_EXTENSION: Record<string, LucideIcon> = {};

function register(icon: LucideIcon, extensions: string[]) {
    for (const extension of extensions) BY_EXTENSION[extension] = icon;
}

register(FileImage, [
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'svg',
    'bmp',
    'ico',
    'avif',
    'tif',
    'tiff',
]);
register(FileText, ['txt', 'md', 'log', 'rtf', 'pdf', 'doc', 'docx', 'odt']);
register(FileCode, [
    'js',
    'mjs',
    'ts',
    'tsx',
    'jsx',
    'cs',
    'go',
    'py',
    'php',
    'rb',
    'java',
    'c',
    'h',
    'cpp',
    'rs',
    'sh',
    'html',
    'css',
    'xml',
    'yml',
    'yaml',
    'toml',
    'ini',
    'sql',
    'env',
    'conf',
]);
register(FileJson, ['json', 'jsonl']);
register(FileArchive, ['zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar']);
register(FileAudio, ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac']);
register(FileVideo, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
register(FileSpreadsheet, ['csv', 'tsv', 'xls', 'xlsx', 'ods']);

/** The extension without the dot, lowercase, or '' for none ("archive.tar.gz" gives "gz"). */
export function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot <= 0 ? '' : name.slice(dot + 1).toLowerCase();
}

type Kind = 'dir' | 'file' | 'link' | 'other' | 'up';

/**
 * An icon for a folder entry: folders and links by kind, files by extension
 * (image, text, code, archive, ...), and a plain file for anything unknown.
 */
export function FileIcon({
    name,
    kind,
    className,
}: {
    name: string;
    kind: Kind;
    className?: string;
}) {
    const Icon: LucideIcon =
        kind === 'up'
            ? FolderUp
            : kind === 'dir'
              ? Folder
              : kind === 'link'
                ? LinkIcon
                : (BY_EXTENSION[extensionOf(name)] ?? File);

    return (
        <Icon
            className={cn(
                'size-4 shrink-0',
                kind === 'dir' || kind === 'up'
                    ? 'text-primary'
                    : 'text-base-content/70',
                className,
            )}
            aria-hidden
        />
    );
}
