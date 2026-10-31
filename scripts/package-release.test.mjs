import assert from 'node:assert/strict'
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import { packageRelease } from './package-release.mjs'

test('packages matching app metadata into a checksummed release archive', async () => {
	const root = await mkdtemp(join(tmpdir(), 'paperlessforwarder-test-'))
	try {
		await mkdir(join(root, 'appinfo'))
		await mkdir(join(root, 'lib'))
		await writeFile(join(root, 'package.json'), JSON.stringify({ version: '1.0.0' }))
		await writeFile(join(root, 'appinfo/info.xml'), '<info><id>paperlessforwarder</id><version>1.0.0</version></info>')
		await writeFile(join(root, 'README.md'), 'readme')
		await writeFile(join(root, 'CHANGELOG.md'), 'changelog')
		await writeFile(join(root, 'lib/service.php'), '<?php')
		const result = await packageRelease(root, 'v1.0.0')
		const digest = (await readFile(`${result.archive}.sha256`, 'utf8')).split(' ')[0]
		assert.equal(digest, result.digest)
		assert.match(result.archive, /paperlessforwarder-1\.0\.0\.tar\.gz$/)
	} finally {
		await rm(root, { recursive: true, force: true })
	}
})

test('rejects a mismatched release tag', async () => {
	const root = await mkdtemp(join(tmpdir(), 'paperlessforwarder-test-'))
	try {
		await mkdir(join(root, 'appinfo'))
		await writeFile(join(root, 'package.json'), JSON.stringify({ version: '1.0.0' }))
		await writeFile(join(root, 'appinfo/info.xml'), '<info><id>paperlessforwarder</id><version>1.0.0</version></info>')
		await assert.rejects(packageRelease(root, 'v2.0.0'), /Release tag/)
	} finally {
		await rm(root, { recursive: true, force: true })
	}
})
