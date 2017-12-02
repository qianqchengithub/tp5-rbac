<?php
namespace app\behind\controller;
use \app\common\controller\CBase;
use app\common\logic\ErrorCode;
use \think\Request;
use \think\Db;
use \app\common\logic\CacheKey;
use think\Session;

class Base extends CBase
{
	protected $error_msg;

	//全局设置
	protected $global_setting;

	private $_prefix;

	//当前操作
	protected $act;
	/**
	* 检测是否已经含有表以便生成admin
	*/
	public function _initialize()
	{
	    parent::_initialize();
	    if(config('app_debug')) {
			Session::delete('auth');
		}
		$action = request()->action();
		$this->_prefix = config('database.prefix');
		if(in_array($action,['create_auth', 'auth_exists', 'create_admin', 'error_page'])) return false;
		try{
			Db::Query("show tables");
		}
		catch(\PDOException $e){
			exit('数据库配置错误或或数据不存在！');
		}
		catch(\think\Exception $e) {
			exit('未配置数据库');
		}
		if(!$this->auth_exists()) {
			$this->redirect('base/create_admin');
		}
		//获取全局设置
		$setting = Db::name('global_setting')->select();
		foreach($setting as $v) {
		    $this->global_setting[$v['key']] = $v['value'];
        }
		//sign控制器时跳出
        if(strtolower(request()->controller()) === 'sign') {
            return false;
        }
		if(!$this->is_login()) {
			$this->redirect('sign/login');
		}

		//获取权限
        $this->get_auth();
        //判断执行权
        $this->view_auth();
		//设置菜单
		$this->set_menu();
		//设置当前访问
        $this->now_act();
		//设置用户信息
        $this->set_user();

	}

	/**
	* 判断是否已存在权限表
	*/
	private function auth_exists():bool
	{
		try {
			return Db::name('admin_user')->select()?true:false;
		} catch (\think\Exception $e) {
			return false;
		}
	}


	//rbac初始化
	final public  function create_admin(Request $request)
	{
		if($this->auth_exists()) {
			$this->redirect('index/index');
		}
		if($request->isAjax()) {
		    $phone = $request->param('admin_phone',0);
            if(!preg_match('/1[3|5|7|8][\d]{9}/',$phone)) {
                $code = 9001;
                goto init_over;
            }

            if($code = $this->create_auth()) {
                goto init_over;
            }
            $id = Db::name('admin_user')->insert([
            	'admin_user' => $request->param('admin_user','','htmlspecialchars'),
            	'admin_pass' => md5(md5($request->param('admin_pass','','htmlspecialchars'))),
            	'admin_name' => $request->param('admin_name','','htmlspecialchars'),
            	'admin_phone'=> $phone,
            	'role_id'    => 1,
            	'create_user_id' => 1,
            	'status'     => 0,
            	'last_login' => time(),
            	'add_time'   => time()
            ]);
            session('user',['id'=>$id,'admin_user' => $request->param('admin_user','','htmlspecialchars'),'admin_name'=>$request->param('admin_name','','htmlspecialchars'),'role_id'=>1]);
            $code = 0;
            init_over:
            return json(['code'=>$code,'msg'=>ErrorCode::error[$code]]);
            
        }
        elseif ($request->isGet()) {
            return $this->fetch();
        }
        else {
		    return '请求错误！';
        }
	}


    /**
     * 生成auth权限表
     */
    private function create_auth()
    {
    	if(!is_file('./create_auth.sql')) {
    		return 9008;
    	}
        $sql = preg_replace('/\[\[PREFIX\]\]/',$this->_prefix,file_get_contents('create_auth.sql'));
        $sql = explode(';', $sql);
        array_pop($sql);
        try{
        	return Db::batchQuery($sql)?0:9009;
        }
        catch(\think\Exception $e){
        	return 9009;
        }
    }

    protected function get_auth()
    {
        if(session('auth') == false) {
            if(session('user.role_id') == 1) {
                session('auth',Db::name('admin_menu')->field('distinct url')->column('url'));
            }
            else {
                session('auth',json_decode(Db::name('admin_role_auth')->where(['role_id'=>session('user.role_id')])->value('role_auth'), true));
            }
        }
        return true;
    }

    /**
    * 判断是否有执行权
    */
    protected function view_auth()
    {
    	$act = strtolower(request()->controller()) . '/' . strtolower(request()->action());
    	if(!in_array($act, session('auth'))) {
    		exit(json_encode(['code'=>10000,'msg'=>ErrorCode::error[10000]]));
    	}
    }

    /**
     * 输出菜单
     */
    protected function set_menu()
    {
        if($menus = session("menu")) goto menu;

        //调试模式可见禁用菜单
    	if(config('app_debug')) {
    		$condition['status'] = ['in',[0,1]];
    	}
    	else {
    		$condition['status'] = 0;
    	}
    	$condition['url'] = ['in',array_merge(session('auth'),[''])];
    	$data = Db::name('admin_menu')->field('id,name,url,parent_id,status')->where($condition)->order('parent_id, sort')->select();
    	$parents = [];
    	$menus = [];
    	foreach($data as $v) {
    	    if($v['parent_id'] == 0 && !array_key_exists($v['name'],$menus)) {
    	        $parents[$v['id']] = $v['name'];
                $menus[$v['name']] = [];
            }
            if($v['parent_id'] != 0 && isset($parents[$v['parent_id']])) {
    	        $v['parent_name'] = $parents[$v['parent_id']];
    	        $menus[$v['parent_name']][] = $v;
            }

        }
        unset($data);
    	session('menu',$menus);
    	menu:
        $this->assign('left_menu',$menus);
    }

    /**
     * 获取当前操作
     */
    protected function now_act()
    {

        $act = strtolower(request()->controller()) . '/' . strtolower(request()->action());
        $menu = session('menu');
        foreach($menu as $v) {
            foreach($v as $vv) {
                if($vv['url'] == $act) {
                    $this->act = ['parent_name' => $vv['parent_name'],'url'=>$vv['url'],'name'=>$vv['name']];
                    break;
                }
            }
        }
        $this->assign('now_act',$this->act);
    }


    /**
     * 输出用户基本信息
     */
    protected function set_user()
    {
        $this->assign('top_user',[
            'admin_name'=> session('user.admin_name')
        ]);
    }

	//error_page
	public function error_page()
	{
		$this->assign('error_msg', $this->error_msg);
		return $this->fetch();
	}

	/**
	* 是否登录
	*/
	protected function is_login():bool
    {
	    if(config('app_debug')) return true;
		return session('user')?true:false;
	}

}
