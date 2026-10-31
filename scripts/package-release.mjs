import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export async function packageRelease(root, tag, output = join(root, 'dist')) {
	const metadata = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'))
	const xml = await readFile(join(root, 'appinfo/info.xml'), 'utf8')
	const id = xml.match(/<id>([^<]+)<\/id>/)?.[1]
	const appVersion = xml.match(/<version>([^<]+)<\/version>/)?.[1]
	const version = metadata.version
	if (id !== 'paperlessforwarder' || appVersion !== version
		|| !/^\d+\.\d+\.\d+$/.test(version) || tag !== `v${version}`) {
		throw new Error('Release tag must match the stable app version')
	}
	const staging = await mkdtemp(join(tmpdir(), 'paperlessforwarder-release-'))
	const archiveName = `${id}-${version}.tar.gz`
	await mkdir(output, { recursive: true })
	try {
		const app = join(staging, id)
		await mkdir(app)
		for (const name of ['appinfo', 'lib', 'README.md', 'CHANGELOG.md']) {
			await cp(join(root, name), join(app, name), { recursive: true })
		}
		const archive = resolve(output, archiveName)
		execFileSync('tar', ['-czf', archive, '-C', staging, id])
		const digest = createHash('sha256').update(await readFile(archive)).digest('hex')
		await writeFile(`${archive}.sha256`, `${digest}  ${archiveName}\n`)
		return { archive, digest, version }
	} finally {
		await rm(staging, { recursive: true, force: true })
	}
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
	const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
	const result = await packageRelease(root, process.argv[2])
	console.log(`Packaged ${result.archive} (${result.digest})`)
}
