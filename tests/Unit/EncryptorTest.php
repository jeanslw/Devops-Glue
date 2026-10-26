<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * Encryptor 单元测试：对称加密（AES-256-GCM）的往返、幂等、透明兼容、篡改检测与密钥校验。
 */
class EncryptorTest extends TestCase
{
    private function key(): string
    {
        return base64_encode(random_bytes(Encryptor::KEY_LENGTH));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $enc = new Encryptor($this->key());
        $plain = 'glpat-abc123';
        $cipher = $enc->encrypt($plain);
        $this->assertStringStartsWith('enc:v1:', $cipher);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, $enc->decrypt($cipher));
    }

    public function testEmptyAndAlreadyEncryptedAreIdempotent(): void
    {
        $enc = new Encryptor($this->key());
        $this->assertSame('', $enc->encrypt(''));
        $this->assertSame('', $enc->decrypt(''));

        $cipher = $enc->encrypt('secret');
        $this->assertSame($cipher, $enc->encrypt($cipher), '已密文值重加密必须原样返回（幂等）');
        $this->assertSame('secret', $enc->decrypt($cipher));
    }

    public function testPlaintextDecryptIsTransparent(): void
    {
        $enc = new Encryptor($this->key());
        $this->assertSame('legacy-plain', $enc->decrypt('legacy-plain'), '存量明文解密必须透明返回（迁移期兼容）');
    }

    public function testRandomIvProducesDifferentCiphertext(): void
    {
        $enc = new Encryptor($this->key());
        $a = $enc->encrypt('same');
        $b = $enc->encrypt('same');
        $this->assertNotSame($a, $b, '随机 IV 下相同明文密文应不同');
        $this->assertSame('same', $enc->decrypt($a));
        $this->assertSame('same', $enc->decrypt($b));
    }

    public function testTamperedCiphertextThrows(): void
    {
        $enc = new Encryptor($this->key());
        $cipher = $enc->encrypt('secret');
        $raw = base64_decode(substr($cipher, strlen(Encryptor::PREFIX)), true);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00"; 
        $tampered = Encryptor::PREFIX . base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        $enc->decrypt($tampered);
    }

    public function testInvalidKeyRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new Encryptor('not-32-byte-key');
    }

    public function testMissingEnvKeyThrows(): void
    {
        $enc = new Encryptor(); 
        unset($_ENV[Encryptor::ENV_KEY]);
        putenv(Encryptor::ENV_KEY);

        $this->expectException(\RuntimeException::class);
        $enc->rawKey();
    }
}
