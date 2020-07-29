<?php
/**
 * mm_ddResizeImage
 * @version 1.5 (2017-04-17)
 * 
 * @desc A widget for ManagerManager plugin that allows image size to be changed (TV) so it is possible to create a little preview (thumb).
 * 
 * @uses PHP >= 5.4.
 * @uses MODXEvo.plugin.ManagerManager >= 0.7.
 * @uses MODXEvo.snippet.ddGetMultipleField => 3.0b (if mm_ddMultipleFields fields unparse is required).
 * 
 * @note $params['replaceDocFieldVal'] doesn`t work if $params['ddMultipleField_isUsed'] == 1!
 * 
 * @param $params {array_associative|stdClass} — The object of params. @required
 * @param $params['fields'] {string_commaSeparated} — The names of TVs for which the widget is applied. @required
 * @param $params['width'] {integer} — Width of the image being created (in px). Empty value means width calculating automatically according to height. At least one of the two parameters must be defined. @required
 * @param $params['height'] {integer} — Height of the image being created (in px). Empty value means height calculating automatically according to width. At least one of the two parameters must be defined. @required
 * @param $params['filenameSuffix'] {string} — The suffix for the images being created. Its empty value makes initial images to be rewritten! Default: '_ddthumb'.
 * @param $params['transformMode'] {'resize'|'crop'|'resizeAndCrop'|'resizeAndFill'} — Cropping status. 'resize' — resize without cropping; 'crop' — cropping without resize (proportions won`t be saved); 'resizeAndCrop' — the image will be resized then cropped; 'resizeAndFill' — the image will be resized with propotions saving, blank space will be filled with “$params['backgroundColor']”. Default: 'resizeAndCrop'.
 * @param $params['backgroundColor'] {string} — Background color. It matters if cropping equals 'fill_resized'. Default: '#ffffff'.
 * @param $params['allowEnlargement'] {0|1} — Allow output enlargement. Default: 1.
 * @param $params['quality'] {integer} — Output image quality level. Default: $modx->getConfig('jpegQuality').
 * @param $params['replaceDocFieldVal'] {0|1} — TV values rewriting status. When this parameter equals 1 then tv values are replaced by the names of the created images. It doesn`t work if multipleField = 1. Default: 0.
 * @param $params['ddMultipleField_isUsed'] {0|1} — Multiple field status (for mm_ddMultipleFields). Default: '0';
 * @param $params['ddMultipleField_columnNumber'] {integer} — The number of the column in which the image is located (for mm_ddMultipleFields). Default: 0.
 * @param $params['ddMultipleField_rowNumber'] {integer|'all'} — The number of the row that will be processed (for mm_ddMultipleFields). Default: 'all'.
 * @param $params['ddMultipleField_rowDelimiter'] {string} — The string delimiter (for mm_ddMultipleFields). Default: '||'.
 * @param $params['ddMultipleField_colDelimiter'] {string} — The column delimiter (for mm_ddMultipleFields). Default: '::'.
 * @param $params['roles'] {string_commaSeparated} — The roles that the widget is applied to (when this parameter is empty then widget is applied to the all roles). Default: '' (to all roles).
 * @param $params['templates'] {string_commaSeparated} — The templates for which the widget is applied (empty value means all templates). Default: '' (to all templates).
 * 
 * @event OnBeforeDocFormSave.
 * 
 * @link http://code.divandesign.biz/modx/mm_ddresizeimage/1.5
 * 
 * @copyright 2012–2017 DivanDesign {@link http://www.DivanDesign.biz }
 */

function mm_ddResizeImage($params){
	global $modx;
	
	$e = &$modx->Event;
	
	//For backward compatibility
	if (func_num_args() > 1){
		//Convert ordered list of params to named
		$params = ddTools::orderedParamsToNamed([
			'paramsList' => func_get_args(),
			'compliance' => [
				'fields',
				'roles',
				'templates',
				'width',
				'height',
				'transformMode',
				'filenameSuffix',
				'replaceDocFieldVal',
				'backgroundColor',
				'ddMultipleField_isUsed',
				'ddMultipleField_columnNumber',
				'ddMultipleField_rowDelimiter',
				'ddMultipleField_colDelimiter',
				'ddMultipleField_rowNumber',
				'allowEnlargement'
			]
		]);
	}
	
	$params = (array) $params;
	
	//For backward compatibility
	$params = array_merge(
		$params,
		ddTools::verifyRenamedParams(
			$params,
			[
				'transformMode' => 'croppingMode'
			]
		)
	);
	
	//Defaults
	$params = (object) array_merge([
// 		'fields' => '',
		'width' => '',
		'height' => '',
		'filenameSuffix' => '_ddthumb',
		'transformMode' => 'resizeAndCrop',
		'backgroundColor' => '#FFFFFF',
		'allowEnlargement' => 1,
		'quality' => $modx->getConfig('jpegQuality'),
		'replaceDocFieldVal' => 0,
		'ddMultipleField_isUsed' => 0,
		'ddMultipleField_columnNumber' => 0,
		'ddMultipleField_rowDelimiter' => '||',
		'ddMultipleField_colDelimiter' => '::',
		'ddMultipleField_rowNumber' => 'all',
		'roles' => '',
		'templates' => ''
	], $params);
	
	//For backward compatibility
	switch ($params->transformMode){
		case '0':
			$params->transformMode = 'resize';
		break;
		
		case '1':
			$params->transformMode = 'crop';
		break;
		
		case 'crop_resized':
			$params->transformMode = 'resizeAndCrop';
		break;
		
		case 'fill_sized':
			$params->transformMode = 'resizeAndFill';
		break;
	}
	
	//Проверим, чтобы было нужное событие, чтобы были заполнены обязательные параметры и что правило подходит под роль
	if ($e->name == 'OnBeforeDocFormSave' &&
		isset($params->fields) &&
		(
			$params->width != '' ||
			$params->height != ''
		) &&
		useThisRule(
			$params->roles,
			$params->templates
		)
	){
		global $mm_current_page, $tmplvars;
		
		//Получаем необходимые tv для данного шаблона (т.к. в mm_ddMultipleFields тип может быть любой, получаем все, а не только изображения)
		$params->fields = tplUseTvs(
			$mm_current_page['template'],
			$params->fields,
			'',
			'id,name'
		);
		
		//Если что-то есть
		if (
			is_array($params->fields) &&
			count($params->fields) > 0
		){
			//Обработка параметров
			$params->replaceDocFieldVal = ($params->replaceDocFieldVal == '1') ? true : false;
			$params->ddMultipleField_isUsed = ($params->ddMultipleField_isUsed == '1') ? true : false;
			
			//Перебираем их
			foreach ($params->fields as $field){
				//Если в значении tv что-то есть
				if (
					isset($tmplvars[$field['id']]) &&
					trim($tmplvars[$field['id']][1]) != ''
				){
					$image = trim($tmplvars[$field['id']][1]);
					
					//Если это множественное поле
					if ($params->ddMultipleField_isUsed){
						//Получим массив изображений
						$images = $modx->runSnippet(
							'ddGetMultipleField',
							[
								'inputString' => $image,
								'rowDelimiter' => $params->ddMultipleField_rowDelimiter,
								'colDelimiter' => $params->ddMultipleField_colDelimiter,
								'startRow' => ($params->ddMultipleField_rowNumber == 'all' ? 0 : $params->ddMultipleField_rowNumber),
								'totalRows' => ($params->ddMultipleField_rowNumber == 'all' ? 'all' : 1),
								'outputFormat' => 'JSON',
								'columns' => $params->ddMultipleField_columnNumber,
								//For backward compatibility with < 3.3
								'string' => $image,
								//For backward compatibility with < 3.0b
								'field' => $image,
								'splY' => $params->ddMultipleField_rowDelimiter,
								'splX' => $params->ddMultipleField_colDelimiter,
								'num' => ($params->ddMultipleField_rowNumber == 'all' ? 0 : $params->ddMultipleField_rowNumber),
								'count' => ($params->ddMultipleField_rowNumber == 'all' ? 'all' : 1),
								'format' => 'JSON',
								'colNum' => $params->ddMultipleField_columnNumber
							]
						);
						
						//Если пришла пустота (ни одного изображения заполнено не было)
						if (trim($images) == ''){
							$images = [];
						}else if ($params->ddMultipleField_rowNumber == 'all'){
							$images = json_decode(
								$images,
								true
							);
						}else{
							$images = [trim(
								stripcslashes($images),
								'\'\"'
							)];
						}
					}else{
						//Запишем в массив одно изображение
						$images = [$image];
					}
					
					foreach ($images as $image){
						//Если есть лишний слэш в начале, убьём его
						if (strpos(
							$image,
							'/'
						) === 0){
							$image = substr(
								$image,
								1
							);
						}
						
						//На всякий случай проверим, что файл существует
						if (file_exists($modx->config['base_path'].$image)){
							//Полный путь изображения
							$imageFullPath = pathinfo($modx->config['base_path'].$image);
							
							//Если имя файла уже заканчивается на суффикс (необходимо при $params->replaceDocFieldVal == 1), не будем его добавлять
							if (substr(
								$imageFullPath['filename'],
								strlen($params->filenameSuffix) * -1
							) == $params->filenameSuffix){
								$params->filenameSuffix = '';
							}
							
							//Имя нового изображения
							$newImageName = $imageFullPath['filename'].$params->filenameSuffix.'.'.$imageFullPath['extension'];
							
							//Делаем превьюшку
							\DDTools\FilesTools::modifyImage([
								//Ссылка на оригинальное изображение
								'sourceFullPathName' => $modx->config['base_path'].$image,
								//Формируем новое имя изображения (полный путь)
								'outputFullPathName' => $imageFullPath['dirname'].'/'.$newImageName,
								//Режим обрезания
								'transformMode' => $params->transformMode,
								//Ширина превьюшки
								'width' => $params->width,
								//Высота превьюшки
								'height' => $params->height,
								//Разрешить ли увеличение изображения
								'allowEnlargement' => $params->allowEnlargement,
								//Фон превьюшки (может понадобиться для заливки пустых мест)
								'backgroundColor' => $params->backgroundColor,
								//Output image quality level
								'quality' => $params->quality
							]);
							
							//Если нужно заменить оригинальное значение TV на вновь созданное и это не $params->ddMultipleField_isUsed
							if (
								$params->replaceDocFieldVal &&
								!$params->ddMultipleField_isUsed
							){
								$tmplvars[$field['id']][1] = dirname($tmplvars[$field['id']][1]).'/'.$newImageName;
							}
						}
					}
				}
			}
		}
	}
}
?>