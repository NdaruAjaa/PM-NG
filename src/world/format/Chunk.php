<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

/**
 * Implementation of MCPE-style chunks with subchunks with XZY ordering.
 */
declare(strict_types=1);

namespace pocketmine\world\format;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\Tile;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function array_map;

class Chunk{
	public const DIRTY_FLAG_BLOCKS = 1 << 0;
	public const DIRTY_FLAG_BIOMES = 1 << 3;

	public const MIN_SUBCHUNK_INDEX = -4;
	public const MAX_SUBCHUNK_INDEX = 19;
	public const MAX_SUBCHUNKS = self::MAX_SUBCHUNK_INDEX - self::MIN_SUBCHUNK_INDEX + 1;

	public const EDGE_LENGTH = SubChunk::EDGE_LENGTH;
	public const COORD_BIT_SIZE = SubChunk::COORD_BIT_SIZE;
	public const COORD_MASK = SubChunk::COORD_MASK;

	private int $terrainDirtyFlags = 0;

	protected ?bool $lightPopulated = false;
	protected bool $terrainPopulated = false;

	/**
	 * @var \SplFixedArray|SubChunk[]
	 * @phpstan-var \SplFixedArray<SubChunk>
	 */
	protected \SplFixedArray $subChunks;

	/** @var Tile[] */
	protected array $tiles = [];

	protected HeightArray $heightMap;

	/** @var int */
	protected $dimensionId;

	/**
	 * @param SubChunk[] $subChunks
	 */
	public function __construct(array $subChunks, bool $terrainPopulated){
		$this->subChunks = new \SplFixedArray(Chunk::MAX_SUBCHUNKS);

		foreach($this->subChunks as $y => $null){
			//TODO: we should probably require all subchunks to be provided here
			$this->subChunks[$y] = $subChunks[$y + self::MIN_SUBCHUNK_INDEX] ?? new SubChunk(BlockTypeIds::AIR << Block::INTERNAL_STATE_DATA_BITS, [], new PalettedBlockArray(BiomeIds::OCEAN));
		}

		$val = (self::MAX_SUBCHUNK_INDEX + 1) * SubChunk::EDGE_LENGTH;
		$this->heightMap = HeightArray::fill($val); //TODO: what about lazily initializing this?

		$this->terrainPopulated = $terrainPopulated;

		// TODO: Hack! There's no way to cleanly do this without diverging from pmmp too much, so this is the best workaround for that
		$this->dimensionId = match($this->getBiomeId(0, 0, 0)) {
			BiomeIds::HELL, BiomeIds::BASALT_DELTAS, BiomeIds::SOULSAND_VALLEY, BiomeIds::CRIMSON_FOREST, BiomeIds::WARPED_FOREST => DimensionIds::NETHER,
			BiomeIds::THE_END => DimensionIds::THE_END,
			default => DimensionIds::OVERWORLD
		};
	}

	/**
	 * Returns the chunk height in count of subchunks.
	 */
	public function getHeight() : int{
		return $this->subChunks->getSize();
	}

	/**
	 * Returns the internal ID of the blockstate at the given coordinates.
	 *
	 * @param int $x 0-15
	 * @param int $y 0-255
	 * @param int $z 0-15
	 *
	 * @return int bitmap, (id << 4) | meta
	 */
	public function getFullBlock(int $x, int $y, int $z, int $layer = 0) : int{
		return $this->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getFullBlock($x, $y & SubChunk::COORD_MASK, $z, $layer);
	}

	/**
	 * Sets the blockstate at the given coordinate by internal ID.
	 */
	public function setFullBlock(int $x, int $y, int $z, int $block, int $layer = 0) : void{
		$this->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->setFullBlock($x, $y & SubChunk::COORD_MASK, $z, $block, $layer);
		$this->terrainDirtyFlags |= self::DIRTY_FLAG_BLOCKS;
	}

	/**
	 * Returns the Y coordinate of the highest non-air block at the specified X/Z chunk block coordinates
	 *
	 * @param int $x 0-15
	 * @param int $z 0-15
	 *
	 * @return int|null 0-255, or null if there are no blocks in the column
	 */
	public function getHighestBlockAt(int $x, int $z) : ?int{
		for($y = self::MAX_SUBCHUNK_INDEX; $y >= self::MIN_SUBCHUNK_INDEX; --$y){
			$height = $this->getSubChunk($y)->getHighestBlockAt($x, $z);
			if($height !== null){
				return $height | ($y << SubChunk::COORD_BIT_SIZE);
			}
		}

		return null;
	}

	/**
	 * Returns the heightmap value at the specified X/Z chunk block coordinates
	 *
	 * @param int $x 0-15
	 * @param int $z 0-15
	 */
	public function getHeightMap(int $x, int $z) : int{
		return $this->heightMap->get($x, $z);
	}

	/**
	 * Returns the heightmap value at the specified X/Z chunk block coordinates
	 *
	 * @param int $x 0-15
	 * @param int $z 0-15
	 */
	public function setHeightMap(int $x, int $z, int $value) : void{
		$this->heightMap->set($x, $z, $value);
	}

	/**
	 * Returns the biome ID at the specified X/Z chunk block coordinates
	 *
	 * @param int $x 0-15
	 * @param int $z 0-15
	 *
	 * @return int 0-255
	 */
	public function getBiomeId(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBiomeArray()->get($x, $y, $z);
	}

	/**
	 * Sets the biome ID at the specified X/Z chunk block coordinates
	 *
	 * @param int $x       0-15
	 * @param int $z       0-15
	 * @param int $biomeId 0-255
	 */
	public function setBiomeId(int $x, int $y, int $z, int $biomeId) : void{
		$this->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBiomeArray()->set($x, $y, $z, $biomeId);
		$this->terrainDirtyFlags |= self::DIRTY_FLAG_BIOMES;
	}

	public function isLightPopulated() : ?bool{
		return $this->lightPopulated;
	}

	public function setLightPopulated(?bool $value = true) : void{
		$this->lightPopulated = $value;
	}

	public function isPopulated() : bool{
		return $this->terrainPopulated;
	}

	public function setPopulated(bool $value = true) : void{
		$this->terrainPopulated = $value;
		$this->terrainDirtyFlags |= self::DIRTY_FLAG_BLOCKS;
	}

	public function addTile(Tile $tile) : void{
		if($tile->isClosed()){
			throw new \InvalidArgumentException("Attempted to add a garbage closed Tile to a chunk");
		}

		$pos = $tile->getPosition();
		if(isset($this->tiles[$index = Chunk::blockHash($pos->x, $pos->y, $pos->z)]) && $this->tiles[$index] !== $tile){
			$this->tiles[$index]->close(); // close the previous tile
		}
		$this->tiles[$index] = $tile;
	}

	public function removeTile(Tile $tile) : void{
		$pos = $tile->getPosition();
		unset($this->tiles[Chunk::blockHash($pos->x, $pos->y, $pos->z)]);
	}

	/**
	 * @return Tile[]
	 */
	public function getTiles() : array{
		return $this->tiles;
	}

	/**
	 * Returns the tile at the specified chunk block coordinates, or null if no tile exists.
	 *
	 * @param int $x 0-15
	 * @param int $y 0-255
	 * @param int $z 0-15
	 */
	public function getTile(int $x, int $y, int $z) : ?Tile{
		return $this->tiles[Chunk::blockHash($x, $y, $z)] ?? null;
	}

	/**
	 * Called when the chunk is unloaded, closing entities and tiles.
	 */
	public function onUnload() : void{
		foreach($this->getTiles() as $tile){
			$tile->close();
		}
	}

	/**
	 * @return int[]
	 */
	public function getHeightMapArray() : array{
		return $this->heightMap->getValues();
	}

	/**
	 * @param int[] $values
	 */
	public function setHeightMapArray(array $values) : void{
		$this->heightMap = new HeightArray($values);
	}

	public function isTerrainDirty() : bool{
		return $this->terrainDirtyFlags !== 0;
	}

	public function getTerrainDirtyFlag(int $flag) : bool{
		return ($this->terrainDirtyFlags & $flag) !== 0;
	}

	public function getTerrainDirtyFlags() : int{
		return $this->terrainDirtyFlags;
	}

	public function setTerrainDirtyFlag(int $flag, bool $value) : void{
		if($value){
			$this->terrainDirtyFlags |= $flag;
		}else{
			$this->terrainDirtyFlags &= ~$flag;
		}
	}

	public function setTerrainDirty() : void{
		$this->terrainDirtyFlags = ~0;
	}

	public function clearTerrainDirtyFlags() : void{
		$this->terrainDirtyFlags = 0;
	}

	public function getDimensionId() : int{
		return $this->dimensionId;
	}

	/**
	 * @see DimensionIds
	 */
	public function setDimensionId(int $dimension) : void{
		$this->dimensionId = $dimension;
	}

	public function getSubChunk(int $y) : SubChunk{
		if($y < self::MIN_SUBCHUNK_INDEX || $y > self::MAX_SUBCHUNK_INDEX){
			throw new \InvalidArgumentException("Invalid subchunk Y coordinate $y");
		}
		return $this->subChunks[$y - self::MIN_SUBCHUNK_INDEX];
	}

	/**
	 * Sets a subchunk in the chunk index
	 */
	public function setSubChunk(int $y, ?SubChunk $subChunk) : void{
		if($y < self::MIN_SUBCHUNK_INDEX || $y > self::MAX_SUBCHUNK_INDEX){
			throw new \InvalidArgumentException("Invalid subchunk Y coordinate $y");
		}

		$this->subChunks[$y - self::MIN_SUBCHUNK_INDEX] = $subChunk ?? new SubChunk(BlockTypeIds::AIR << Block::INTERNAL_STATE_DATA_BITS, [], new PalettedBlockArray(BiomeIds::OCEAN));
		$this->setTerrainDirtyFlag(self::DIRTY_FLAG_BLOCKS, true);
	}

	/**
	 * @return SubChunk[]
	 * @phpstan-return array<int, SubChunk>
	 */
	public function getSubChunks() : array{
		$result = [];
		foreach($this->subChunks as $yOffset => $subChunk){
			$result[$yOffset + self::MIN_SUBCHUNK_INDEX] = $subChunk;
		}
		return $result;
	}

	/**
	 * Disposes of empty subchunks and frees data where possible
	 */
	public function collectGarbage() : void{
		foreach($this->subChunks as $y => $subChunk){
			$subChunk->collectGarbage();
		}
	}

	public function __clone(){
		//we don't bother cloning entities or tiles since it's impractical to do so (too many dependencies)
		$this->subChunks = \SplFixedArray::fromArray(array_map(function(SubChunk $subChunk) : SubChunk{
			return clone $subChunk;
		}, $this->subChunks->toArray()));
		$this->heightMap = clone $this->heightMap;
	}

	/**
	 * Hashes the given chunk block coordinates into a single integer.
	 *
	 * @param int $x 0-15
	 * @param int $y 0-255
	 * @param int $z 0-15
	 */
	public static function blockHash(int $x, int $y, int $z) : int{
		return ($y << (2 * SubChunk::COORD_BIT_SIZE)) |
			(($z & SubChunk::COORD_MASK) << SubChunk::COORD_BIT_SIZE) |
			($x & SubChunk::COORD_MASK);
	}
}
